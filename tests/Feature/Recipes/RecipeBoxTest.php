<?php

namespace Tests\Feature\Recipes;

use App\Jobs\ImportRecipeJob;
use App\Jobs\ProcessCaptureJob;
use App\Models\Capture;
use App\Models\Household;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Recipes\Contracts\RecipeReader;
use App\Services\Recipes\LooksLikeAMealIdea;
use App\Services\Recipes\RecipeSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeRecipeReader;
use Tests\TestCase;

class RecipeBoxTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->app->instance(RecipeReader::class, new FakeRecipeReader);
        Http::fake();

        // These are tests of the box, not of the pipeline. Without this the
        // sync driver runs every import inline and their failures surface here
        // rather than in RecipeImportTest, where they belong.
        Queue::fake([ImportRecipeJob::class, ProcessCaptureJob::class]);
    }

    #[Test]
    public function the_save_dialog_keeps_its_heading_and_its_buttons_in_view(): void
    {
        // Reported from a phone: the dialog's top — heading, mode tabs and the
        // field itself — was scrolled out of sight with nothing to say so, and
        // a recipe could not be added at all. Pinned bands are the fix, and
        // this is what keeps them.
        $html = Livewire::test('recipes.box')->set('saving', true)->html();

        $this->assertStringContainsString('shrink-0', $html);
        $this->assertStringContainsString('min-h-0 flex-1 overflow-y-auto', $html);

        // Save sits outside the form now, so it needs to name it.
        $this->assertStringContainsString('form="save-meal-idea"', $html);
        $this->assertStringContainsString('id="save-meal-idea"', $html);
    }

    #[Test]
    public function the_save_button_still_saves_from_outside_the_form(): void
    {
        Livewire::test('recipes.box')
            ->set('saving', true)
            ->set('mode', 'url')
            ->set('url', 'https://example.com/curry')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('recipes', ['source_url' => 'https://example.com/curry']);
    }

    protected function recipe(array $attributes = []): Recipe
    {
        return Recipe::factory()->create($attributes + ['household_id' => $this->household->id]);
    }

    #[Test]
    public function the_page_requires_a_signed_in_parent(): void
    {
        auth()->logout();

        $this->get('/app/recipes')->assertRedirect('/login');
    }

    #[Test]
    public function saved_ideas_are_listed_with_what_is_known_about_them(): void
    {
        $this->recipe(['title' => 'Chicken and chorizo traybake', 'source_url' => 'https://bbcgoodfood.com/x']);

        Livewire::test('recipes.box')
            ->assertSee('Chicken and chorizo traybake')
            ->assertSee('bbcgoodfood.com')
            ->assertSee('4 servings');
    }

    #[Test]
    public function a_card_still_being_read_says_so_rather_than_looking_empty(): void
    {
        $this->recipe(['title' => 'Idea from instagram.com', 'status' => 'pending']);

        Livewire::test('recipes.box')
            ->assertSee('Idea from instagram.com')
            ->assertSee('Reading it…');
    }

    #[Test]
    public function a_failed_card_offers_a_retry(): void
    {
        $recipe = $this->recipe(['status' => 'failed', 'error' => 'That link could not be opened.']);

        Livewire::test('recipes.box')
            ->assertSee('That link could not be opened.')
            ->call('retry', $recipe->id);

        $this->assertSame('pending', $recipe->fresh()->status);
    }

    #[Test]
    public function a_thin_card_says_why_it_is_thin(): void
    {
        $this->recipe([
            'title' => 'Miso salmon',
            'source_note' => 'Instagram.com needed a login, so this is from the text you shared.',
        ]);

        Livewire::test('recipes.box')->assertSee('needed a login');
    }

    #[Test]
    public function ideas_can_be_starred_and_filtered_down_to_favourites(): void
    {
        $keeper = $this->recipe(['title' => 'Miso salmon']);
        $this->recipe(['title' => 'Something else']);

        Livewire::test('recipes.box')
            ->call('toggleFavourite', $keeper->id)
            ->set('favouritesOnly', true)
            ->assertSee('Miso salmon')
            ->assertDontSee('Something else');

        $this->assertTrue($keeper->fresh()->is_favourite);
    }

    #[Test]
    public function the_tag_filter_only_offers_tags_that_exist(): void
    {
        $this->recipe(['title' => 'Miso salmon', 'tags' => ['quick', 'fish']]);
        $this->recipe(['title' => 'Sunday roast', 'tags' => ['weekend']]);

        Livewire::test('recipes.box')
            ->assertSee('fish')
            ->assertDontSee('freezer')
            ->set('tag', 'weekend')
            ->assertSee('Sunday roast')
            ->assertDontSee('Miso salmon');
    }

    #[Test]
    public function a_link_typed_in_by_hand_is_saved(): void
    {
        Livewire::test('recipes.box')
            ->call('startSaving', 'url')
            ->set('url', 'https://bbcgoodfood.com/traybake')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('recipes', ['source_url' => 'https://bbcgoodfood.com/traybake']);
    }

    #[Test]
    public function the_wall_cannot_delete_a_recipe_but_a_phone_can(): void
    {
        $recipe = $this->recipe();

        Livewire::test('recipes.box')->call('forget', $recipe->id);
        $this->assertModelExists($recipe);

        Livewire::test('recipes.box', ['editable' => true])->call('forget', $recipe->id);
        $this->assertModelMissing($recipe);
    }

    #[Test]
    public function a_misfiled_recipe_can_be_sent_to_review_instead(): void
    {
        $recipe = $this->recipe(['title' => 'Year 4 bake sale', 'raw_text' => 'Bring cakes on Friday.']);

        Livewire::test('recipes.box', ['editable' => true])->call('sendToReview', $recipe->id);

        $this->assertModelMissing($recipe);
        $this->assertDatabaseHas('captures', ['subject' => 'Year 4 bake sale']);
    }

    #[Test]
    public function a_misfiled_capture_can_be_sent_to_the_recipe_box(): void
    {
        $capture = Capture::factory()->reviewing()->create([
            'household_id' => $this->household->id,
            'subject' => 'Miso salmon',
            'body_text' => 'Ingredients: salmon, miso, honey.',
        ]);

        Livewire::test('capture.review')->call('saveAsMealIdea', $capture->id);

        $this->assertDatabaseHas('recipes', ['household_id' => $this->household->id]);
        $this->assertSame('done', $capture->fresh()->status);
    }

    #[Test]
    public function a_shared_reel_goes_to_the_recipe_box_and_not_the_calendar_queue(): void
    {
        $this->post(route('share-target'), [
            'url' => 'https://www.instagram.com/reel/abc123/',
            'text' => 'Weeknight miso salmon, so good',
        ])->assertRedirect(route('recipes'));

        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('captures', 0);
    }

    #[Test]
    public function a_shared_school_letter_still_goes_to_review(): void
    {
        $this->post(route('share-target'), [
            'title' => 'Parents evening',
            'text' => 'Parents evening is on the 14th. Please book a slot.',
        ])->assertRedirect(route('review'));

        $this->assertDatabaseCount('recipes', 0);
        $this->assertDatabaseCount('captures', 1);
    }

    #[Test]
    public function the_sniffer_needs_more_than_the_word_ingredients(): void
    {
        $sniffer = new LooksLikeAMealIdea;

        // An allergens notice is not a recipe.
        $this->assertFalse($sniffer->decide(null, 'Please note the ingredients list has changed.'));
        $this->assertTrue($sniffer->decide(null, 'Ingredients: 200g oats. Method: mix and bake.'));
        $this->assertTrue($sniffer->decide('https://www.tiktok.com/@x/video/1', null));
        $this->assertFalse($sniffer->decide('https://school.example/newsletter.pdf', null));
    }

    #[Test]
    public function the_recipe_schema_uses_only_keywords_structured_outputs_accept(): void
    {
        // The same 400s that bit the capture schema: no minimum/maximum, and
        // nullability through anyOf rather than a type union.
        $banned = ['minimum', 'maximum', 'multipleOf', 'minLength', 'maxLength', 'pattern', 'maxItems'];
        $json = json_encode(RecipeSchema::schema());

        foreach ($banned as $keyword) {
            $this->assertStringNotContainsString('"'.$keyword.'"', $json, "{$keyword} is rejected by the API.");
        }

        $this->assertNoTypeUnions(RecipeSchema::schema());
        $this->assertObjectsAreClosed(RecipeSchema::schema());
    }

    protected function assertNoTypeUnions(array $schema): void
    {
        foreach ($schema as $key => $value) {
            if ($key === 'type') {
                $this->assertIsString($value, 'Nullability must use anyOf, not a ["string","null"] union.');
            }

            if (is_array($value)) {
                $this->assertNoTypeUnions($value);
            }
        }
    }

    protected function assertObjectsAreClosed(array $schema): void
    {
        if (($schema['type'] ?? null) === 'object') {
            $this->assertArrayHasKey('additionalProperties', $schema, 'Every object needs additionalProperties: false.');
            $this->assertFalse($schema['additionalProperties']);
        }

        foreach ($schema as $value) {
            if (is_array($value)) {
                $this->assertObjectsAreClosed($value);
            }
        }
    }
}
