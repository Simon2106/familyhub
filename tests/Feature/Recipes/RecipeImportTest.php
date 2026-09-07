<?php

namespace Tests\Feature\Recipes;

use App\Jobs\ImportRecipeJob;
use App\Models\Household;
use App\Models\Recipe;
use App\Services\Recipes\Contracts\RecipeReader;
use App\Services\Recipes\RecipeImporter;
use App\Services\Recipes\RecipeIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeRecipeReader;
use Tests\TestCase;

/**
 * Saving a meal idea, and the several shapes one arrives in.
 */
class RecipeImportTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected FakeRecipeReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();

        $this->reader = new FakeRecipeReader;
        $this->app->instance(RecipeReader::class, $this->reader);
    }

    protected function page(string $body): void
    {
        Http::fake(['*' => Http::response($body)]);
    }

    /**
     * Import the way a worker would.
     *
     * The sync driver re-throws after failed(), so a test that only wants to
     * see the failed card has to catch it — exactly as the capture pipeline
     * tests do.
     */
    protected function importNow(Recipe $recipe): Recipe
    {
        $job = new ImportRecipeJob($recipe);

        try {
            $job->handle(app(RecipeImporter::class));
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        return $recipe->fresh();
    }

    #[Test]
    public function a_saved_link_becomes_a_card_that_is_visibly_being_read(): void
    {
        Queue::fake([ImportRecipeJob::class]);

        $recipe = app(RecipeIntake::class)->fromUrl('https://bbcgoodfood.com/traybake');

        Queue::assertPushed(ImportRecipeJob::class);

        // The row exists before anything has been read, so nothing shared ever
        // disappears into a queue without acknowledgement.
        $this->assertSame('pending', $recipe->status);
        $this->assertSame('Idea from bbcgoodfood.com', $recipe->title);
        $this->assertSame('https://bbcgoodfood.com/traybake', $recipe->source_url);
    }

    #[Test]
    public function a_fetched_page_fills_in_the_card(): void
    {
        $this->page(<<<'HTML'
        <html><head><title>Traybake recipe | Good Food</title>
        <meta property="og:image" content="https://images.example/traybake.jpg"></head>
        <body><h1>Chicken and chorizo traybake</h1>
        <p>Serves 4. Ingredients: 4 chicken thighs, 200g chorizo, 1 red onion,
        2 tbsp olive oil, 1 tsp smoked paprika. Method: preheat the oven to 200C,
        put everything in a tin and roast for 40 minutes until the chicken is
        cooked through and the chorizo has crisped at the edges. Season to taste
        and serve with crusty bread or a green salad on the side.</p></body></html>
        HTML);

        $recipe = app(RecipeIntake::class)->fromUrl('https://bbcgoodfood.com/traybake');

        $recipe->refresh();

        $this->assertSame('ready', $recipe->status);
        $this->assertSame('Chicken and chorizo traybake', $recipe->title);
        $this->assertSame(4, $recipe->servings);
        $this->assertCount(2, $recipe->ingredients);
        $this->assertSame('https://images.example/traybake.jpg', $recipe->hero_image_url);
        $this->assertStringContainsString('chorizo', $this->reader->sentText());
    }

    #[Test]
    public function a_login_walled_link_falls_back_to_the_shared_caption(): void
    {
        // What Instagram actually returns to a logged-out fetch: a shell.
        $this->page('<html><head><title>Instagram</title></head><body>Log in to continue.</body></html>');

        $caption = 'Weeknight miso salmon. Ingredients: 4 salmon fillets, 2 tbsp white miso, '
            .'1 tbsp honey, 1 tbsp soy. Method: mix, spread on the salmon, grill for 8 minutes.';

        $recipe = app(RecipeIntake::class)->fromUrl('https://www.instagram.com/p/abc123/', $caption);

        $recipe->refresh();

        $this->assertSame('ready', $recipe->status);
        $this->assertStringContainsString('needed a login', $recipe->source_note);
        $this->assertStringContainsString('so this is from the text you shared', $recipe->source_note);
        $this->assertStringContainsString('miso salmon', $this->reader->sentText());
    }

    #[Test]
    public function an_unreachable_link_with_nothing_to_fall_back_on_fails_honestly(): void
    {
        Http::fake(['*' => Http::response('nope', 403)]);
        Queue::fake([ImportRecipeJob::class]);

        $recipe = $this->importNow(app(RecipeIntake::class)->fromUrl('https://www.instagram.com/p/abc123/'));

        $this->assertSame('failed', $recipe->status);
        $this->assertStringContainsString('no text saved with it', $recipe->error);
    }

    #[Test]
    public function a_photo_of_a_cookbook_page_is_sent_as_an_image(): void
    {
        Storage::fake();

        $recipe = app(RecipeIntake::class)->fromPhoto(
            UploadedFile::fake()->image('cookbook.jpg', 1200, 1600)
        );

        $recipe->refresh();

        $this->assertSame('ready', $recipe->status);
        $this->assertContains('image', $this->reader->lastBlockTypes());
        $this->assertStringContainsString('photograph of a recipe', $this->reader->sentText());
        Storage::assertExists($recipe->image_path);
    }

    #[Test]
    public function pasted_text_needs_no_network_at_all(): void
    {
        Http::fake();

        $recipe = app(RecipeIntake::class)->fromText("Nanny's flapjacks\n200g oats, 100g butter, 3 tbsp golden syrup.");

        $recipe->refresh();

        $this->assertSame('ready', $recipe->status);
        $this->assertStringContainsString('flapjacks', $this->reader->sentText());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_failed_import_keeps_what_was_shared_so_it_can_be_retried(): void
    {
        Http::fake();
        Queue::fake([ImportRecipeJob::class]);
        $this->reader->fails('The model was unavailable.');

        $recipe = $this->importNow(app(RecipeIntake::class)->fromText('Miso salmon, 4 fillets, 2 tbsp miso.'));

        $this->assertSame('failed', $recipe->status);
        $this->assertStringContainsString('unavailable', $recipe->error);
        $this->assertStringContainsString('Miso salmon', $recipe->raw_text);
    }

    #[Test]
    public function a_retry_does_not_overwrite_a_recipe_that_already_read_cleanly(): void
    {
        $recipe = Recipe::factory()->create([
            'household_id' => $this->household->id,
            'title' => 'Edited by hand',
        ]);

        (new ImportRecipeJob($recipe))->handle(app(RecipeImporter::class));

        $this->assertSame('Edited by hand', $recipe->fresh()->title);
        $this->assertSame([], $this->reader->calls);
    }

    #[Test]
    public function the_model_is_told_the_things_it_cannot_see(): void
    {
        $this->page(str_repeat('Ingredients: 200g oats. Method: mix and bake. ', 30));

        app(RecipeIntake::class)->fromUrl('https://example.test/flapjacks');

        $sent = $this->reader->sentText();

        $this->assertStringContainsString('It was saved from: https://example.test/flapjacks', $sent);
        $this->assertStringContainsString('Save this as a recipe card', $sent);
    }

    #[Test]
    public function quantities_and_units_come_back_ready_for_a_shopping_list(): void
    {
        Http::fake();

        $this->reader->returns([
            'title' => 'Flapjacks',
            'ingredients' => [
                ['quantity' => 200, 'unit' => 'G', 'item' => 'Rolled Oats', 'note' => null],
                ['quantity' => -1, 'unit' => null, 'item' => 'butter', 'note' => null],
                ['quantity' => 3, 'unit' => 'tbsp', 'item' => '', 'note' => 'golden syrup'],
            ],
        ]);

        $recipe = app(RecipeIntake::class)->fromText('Flapjacks, oats and butter and syrup.')->fresh();

        // Lowercased for merging, a nonsense quantity dropped rather than
        // trusted, and a row with no ingredient discarded entirely.
        // 200 rather than 200.0: JSON does not keep the trailing zero, and the
        // shopping list merge reads these back with is_numeric.
        $this->assertSame(
            [
                ['quantity' => 200, 'unit' => 'g', 'item' => 'rolled oats', 'note' => null],
                ['quantity' => null, 'unit' => null, 'item' => 'butter', 'note' => null],
            ],
            $recipe->ingredients,
        );
    }
}
