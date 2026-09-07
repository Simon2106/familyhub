<?php

namespace Tests\Feature\Capture;

use App\Jobs\ProcessCaptureJob;
use App\Models\Capture;
use App\Models\CaptureItem;
use App\Models\CaptureSource;
use App\Models\Household;
use App\Models\User;
use App\Services\Capture\CaptureIntake;
use App\Services\Capture\Contracts\ItemExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeItemExtractor;
use Tests\TestCase;

/**
 * The review card has to say where an item came from. An item read out of the
 * PDF carries different weight from one guessed off a covering note.
 */
class ItemSourceTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected FakeItemExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->extractor = new FakeItemExtractor;
        $this->app->instance(ItemExtractor::class, $this->extractor);
    }

    protected function process(string $label, string $kind, array $items, string $summary): Capture
    {
        $capture = app(CaptureIntake::class)->create('email', [
            'household' => $this->household,
            'subject' => 'FW: Flu vaccination',
            'body_text' => 'See attached.',
        ], dispatch: false);

        $this->extractor->fromSource($label, $kind)->queueItems($items, $summary);

        dispatch_sync(new ProcessCaptureJob($capture));

        return $capture->fresh();
    }

    #[Test]
    public function what_was_read_is_recorded(): void
    {
        $this->process('flu-letter.pdf', 'attachment', [
            ['type' => 'event', 'title' => 'Flu vaccination', 'start' => '2026-09-25', 'confidence' => 95],
        ], 'A letter about the flu vaccination.');

        $source = CaptureSource::firstOrFail();

        $this->assertSame('flu-letter.pdf', $source->label);
        $this->assertSame('attachment', $source->kind);
        $this->assertSame('A letter about the flu vaccination.', $source->summary);
    }

    #[Test]
    public function an_item_points_at_the_document_it_came_from(): void
    {
        $this->process('flu-letter.pdf', 'attachment', [
            ['type' => 'event', 'title' => 'Flu vaccination', 'start' => '2026-09-25', 'confidence' => 95],
        ], 'A letter.');

        $item = CaptureItem::firstOrFail();

        $this->assertNotNull($item->capture_source_id);
        $this->assertSame('flu-letter.pdf', $item->source->label);
    }

    #[Test]
    public function the_card_names_the_attachment_an_item_came_from(): void
    {
        $this->process('flu-letter.pdf', 'attachment', [
            ['type' => 'event', 'title' => 'Flu vaccination', 'start' => '2026-09-25', 'confidence' => 95],
        ], 'A letter.');

        Livewire::test('capture.review')->assertSee('from flu-letter.pdf');
    }

    #[Test]
    public function an_item_from_the_body_is_named_plainly(): void
    {
        // "from message body" reads like a bug; "from the message" does not.
        $this->process('message body', 'body', [
            ['type' => 'task', 'title' => 'Ring the office', 'start' => null, 'confidence' => 60],
        ], 'A short note.');

        Livewire::test('capture.review')->assertSee('from the message');
    }

    #[Test]
    public function the_card_offers_the_models_summary_of_each_document(): void
    {
        $this->process('flu-letter.pdf', 'attachment', [
            ['type' => 'event', 'title' => 'Flu vaccination', 'start' => '2026-09-25', 'confidence' => 95],
        ], 'A letter giving the vaccination date and a consent deadline.');

        Livewire::test('capture.review')
            ->assertSee('Show source')
            ->assertSee('A letter giving the vaccination date and a consent deadline.')
            ->assertSee('1 found');
    }

    #[Test]
    public function a_capture_with_no_sources_shows_no_toggle(): void
    {
        // Older captures, from before sources were recorded.
        $capture = Capture::factory()->reviewing()->create(['household_id' => $this->household->id]);
        CaptureItem::factory()->create(['capture_id' => $capture->id, 'title' => 'Something']);

        Livewire::test('capture.review')
            ->assertSee('Something')
            ->assertDontSee('Show source');
    }

    #[Test]
    public function an_item_without_a_source_still_renders(): void
    {
        // Items captured before sources were recorded have none.
        $capture = Capture::factory()->reviewing()->create(['household_id' => $this->household->id]);
        $item = CaptureItem::factory()->create(['capture_id' => $capture->id, 'title' => 'Orphan item']);

        $this->assertNull($item->source);

        Livewire::test('capture.review')
            ->assertSee('Orphan item')
            ->assertDontSee('from the message');
    }

    #[Test]
    public function the_card_does_not_query_once_per_source(): void
    {
        $capture = Capture::factory()->reviewing()->create(['household_id' => $this->household->id]);

        foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $label) {
            $source = CaptureSource::create([
                'capture_id' => $capture->id, 'label' => $label, 'kind' => 'attachment',
                'summary' => 'Read it.',
            ]);
            CaptureItem::factory()->create([
                'capture_id' => $capture->id, 'capture_source_id' => $source->id,
            ]);
        }

        $measure = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            Livewire::test('capture.review');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // Warm-up: the first render resolves the household, which later ones
        // take from the request cache.
        $measure();

        $withThree = $measure();

        foreach (['d.pdf', 'e.pdf', 'f.pdf'] as $label) {
            $source = CaptureSource::create([
                'capture_id' => $capture->id, 'label' => $label, 'kind' => 'attachment',
                'summary' => 'Read it.',
            ]);
            CaptureItem::factory()->create([
                'capture_id' => $capture->id, 'capture_source_id' => $source->id,
            ]);
        }

        $withSix = $measure();

        $this->assertSame(
            $withThree,
            $withSix,
            "Query count went from {$withThree} to {$withSix} as sources doubled — that is an N+1.",
        );
    }

    #[Test]
    public function token_counts_are_kept_against_the_source(): void
    {
        // What each document cost to read, for when a capture looks expensive.
        $capture = Capture::factory()->create(['household_id' => $this->household->id]);

        $source = CaptureSource::create([
            'capture_id' => $capture->id, 'label' => 'big.pdf', 'kind' => 'attachment',
            'input_tokens' => 12345, 'output_tokens' => 678,
        ]);

        $this->assertSame(12345, $source->fresh()->input_tokens);
        $this->assertSame(678, $source->fresh()->output_tokens);
    }
}
