<?php

namespace Tests\Feature\Capture;

use App\Models\Capture;
use App\Models\Household;
use App\Services\Capture\ExtractedItem;
use App\Services\Capture\ExtractionResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A capture with several attachments is several calls, not one.
 *
 * A single call meant a newsletter with two large PDFs spent most of one
 * response budget on the first, and the rest came back truncated — a term
 * calendar needs every date, not most of them.
 */
class SplitExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function item(string $title, ?string $start = null, int $confidence = 90, array $extra = []): ExtractedItem
    {
        return new ExtractedItem(
            type: 'event',
            title: $title,
            startAt: $start === null ? null : CarbonImmutable::parse($start),
            endAt: $extra['end'] ?? null,
            location: $extra['location'] ?? null,
            notes: $extra['notes'] ?? null,
            memberHint: $extra['hint'] ?? null,
            confidence: $confidence,
        );
    }

    #[Test]
    public function results_from_each_call_are_combined(): void
    {
        $merged = ExtractionResult::merge([
            new ExtractionResult([$this->item('Parents evening', '2026-09-15 18:00')], 'The covering email.'),
            new ExtractionResult([$this->item('Inset day', '2026-10-09')], 'The term calendar.'),
            new ExtractionResult([$this->item('Sports day', '2026-06-12')], 'The trip letter.'),
        ]);

        $this->assertCount(3, $merged->items);
        $this->assertSame(
            ['Parents evening', 'Inset day', 'Sports day'],
            array_map(fn ($i) => $i->title, $merged->items),
        );
    }

    #[Test]
    public function the_same_event_found_twice_is_folded_together(): void
    {
        // Splitting introduces this: the covering email mentions the date and
        // the PDF it refers to repeats it. The household should not have to
        // reject the same thing twice.
        $merged = ExtractionResult::merge([
            new ExtractionResult([$this->item('Parents evening', '2026-09-15 18:00')]),
            new ExtractionResult([$this->item('parents  evening', '2026-09-15 18:00')]),
        ]);

        $this->assertCount(1, $merged->items);
    }

    #[Test]
    public function the_more_confident_reading_wins(): void
    {
        $merged = ExtractionResult::merge([
            new ExtractionResult([$this->item('Parents evening', '2026-09-15 18:00', confidence: 55)]),
            new ExtractionResult([$this->item('Parents evening', '2026-09-15 18:00', confidence: 95)]),
        ]);

        $this->assertSame(95, $merged->items[0]->confidence);
    }

    #[Test]
    public function at_equal_confidence_the_fuller_reading_wins(): void
    {
        // The PDF usually carries the location the covering email left out.
        $merged = ExtractionResult::merge([
            new ExtractionResult([$this->item('Parents evening', '2026-09-15 18:00')]),
            new ExtractionResult([$this->item('Parents evening', '2026-09-15 18:00', extra: [
                'location' => 'School hall',
                'hint' => 'Year 4',
            ])]),
        ]);

        $this->assertCount(1, $merged->items);
        $this->assertSame('School hall', $merged->items[0]->location);
        $this->assertSame('Year 4', $merged->items[0]->memberHint);
    }

    #[Test]
    public function the_same_title_on_different_dates_stays_two_items(): void
    {
        // Three parents evenings on three nights are three commitments.
        $merged = ExtractionResult::merge([
            new ExtractionResult([$this->item('Parents evening', '2026-09-15 18:00')]),
            new ExtractionResult([$this->item('Parents evening', '2026-09-16 18:00')]),
        ]);

        $this->assertCount(2, $merged->items);
    }

    #[Test]
    public function undated_items_of_the_same_name_still_fold(): void
    {
        $merged = ExtractionResult::merge([
            new ExtractionResult([$this->item('Book the school photo')]),
            new ExtractionResult([$this->item('Book the school photo')]),
        ]);

        $this->assertCount(1, $merged->items);
    }

    #[Test]
    public function an_undated_item_is_not_folded_into_a_dated_one(): void
    {
        // They may genuinely be different: one a deadline, one a reminder.
        $merged = ExtractionResult::merge([
            new ExtractionResult([$this->item('Trip form')]),
            new ExtractionResult([$this->item('Trip form', '2026-09-12')]),
        ]);

        $this->assertCount(2, $merged->items);
    }

    #[Test]
    public function summaries_from_every_call_are_kept(): void
    {
        $merged = ExtractionResult::merge([
            new ExtractionResult([], 'The covering email.'),
            new ExtractionResult([], 'The term calendar.'),
        ]);

        $this->assertStringContainsString('The covering email.', $merged->summary);
        $this->assertStringContainsString('The term calendar.', $merged->summary);
    }

    #[Test]
    public function an_identical_summary_is_not_repeated(): void
    {
        $merged = ExtractionResult::merge([
            new ExtractionResult([], 'A school newsletter.'),
            new ExtractionResult([], 'A school newsletter.'),
        ]);

        $this->assertSame('A school newsletter.', $merged->summary);
    }

    #[Test]
    public function merging_nothing_is_an_empty_result(): void
    {
        $merged = ExtractionResult::merge([new ExtractionResult, new ExtractionResult]);

        $this->assertTrue($merged->isEmpty());
        $this->assertNull($merged->summary);
    }
}
