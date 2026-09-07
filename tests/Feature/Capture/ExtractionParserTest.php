<?php

namespace Tests\Feature\Capture;

use App\Services\Capture\ExtractionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The parser is what stands between a model's answer and the review inbox, so
 * it is tested against the shapes a model actually produces.
 */
class ExtractionParserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reads_a_well_formed_item(): void
    {
        $result = ExtractionParser::fromArray([
            'summary' => 'A school newsletter.',
            'items' => [[
                'type' => 'event',
                'title' => 'Parents evening',
                'start' => '2026-09-15T18:00:00',
                'end' => '2026-09-15T20:00:00',
                'all_day' => false,
                'location' => 'School hall',
                'notes' => 'Booking opens Monday.',
                'member_hint' => 'Year 4',
                'confidence' => 95,
            ]],
        ]);

        $this->assertSame('A school newsletter.', $result->summary);
        $this->assertCount(1, $result->items);

        $item = $result->items[0];
        $this->assertSame('Parents evening', $item->title);
        $this->assertSame('Year 4', $item->memberHint);
        $this->assertSame(95, $item->confidence);
        // Parsed in household time; the model is told to answer in local time.
        $this->assertSame('2026-09-15 18:00', $item->startAt->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_date_without_a_time_is_treated_as_all_day(): void
    {
        $result = ExtractionParser::fromArray(['items' => [[
            'type' => 'event',
            'title' => 'Inset day',
            'start' => '2026-09-15',
            'end' => null,
            'all_day' => false, // the model said otherwise; the value wins
            'confidence' => 80,
        ]]]);

        $this->assertTrue($result->items[0]->allDay);
        $this->assertSame('2026-09-15', $result->items[0]->startAt->toDateString());
    }

    #[Test]
    public function one_malformed_item_does_not_discard_the_rest(): void
    {
        // A term calendar can hold thirty items; losing twenty-nine because of
        // one bad entry would be far worse than dropping the one.
        $result = ExtractionParser::fromArray(['items' => [
            ['type' => 'event', 'title' => 'Good one', 'start' => '2026-09-15', 'confidence' => 90],
            ['type' => 'event', 'title' => '', 'start' => '2026-09-16', 'confidence' => 90],
            'not an object',
            ['type' => 'event', 'title' => 'Another good one', 'start' => '2026-09-17', 'confidence' => 90],
        ]]);

        $this->assertSame(['Good one', 'Another good one'], array_map(fn ($i) => $i->title, $result->items));
    }

    #[Test]
    public function an_unparseable_date_becomes_an_undated_item(): void
    {
        // Better in the inbox with no date than silently dropped.
        $result = ExtractionParser::fromArray(['items' => [[
            'type' => 'event', 'title' => 'Sports day', 'start' => 'sometime in the summer', 'confidence' => 30,
        ]]]);

        $this->assertCount(1, $result->items);
        $this->assertNull($result->items[0]->startAt);
    }

    #[Test]
    public function a_wildly_wrong_year_is_rejected_rather_than_put_on_the_wall(): void
    {
        $result = ExtractionParser::fromArray(['items' => [[
            'type' => 'event', 'title' => 'Trip', 'start' => '0202-09-15', 'confidence' => 60,
        ]]]);

        $this->assertNull($result->items[0]->startAt);
    }

    #[Test]
    public function an_end_before_the_start_is_dropped(): void
    {
        $result = ExtractionParser::fromArray(['items' => [[
            'type' => 'event', 'title' => 'Trip', 'start' => '2026-09-15T10:00:00',
            'end' => '2026-09-14T10:00:00', 'confidence' => 60,
        ]]]);

        $this->assertNull($result->items[0]->endAt);
    }

    #[Test]
    public function confidence_is_clamped(): void
    {
        $result = ExtractionParser::fromArray(['items' => [
            ['type' => 'event', 'title' => 'A', 'start' => '2026-09-15', 'confidence' => 900],
            ['type' => 'event', 'title' => 'B', 'start' => '2026-09-15', 'confidence' => -5],
        ]]);

        $this->assertSame(100, $result->items[0]->confidence);
        $this->assertSame(0, $result->items[1]->confidence);
    }

    #[Test]
    public function an_unknown_type_falls_back_to_event(): void
    {
        $result = ExtractionParser::fromArray(['items' => [[
            'type' => 'appointment', 'title' => 'Dentist', 'start' => '2026-09-15', 'confidence' => 60,
        ]]]);

        $this->assertSame('event', $result->items[0]->type);
    }

    #[Test]
    public function the_string_null_is_treated_as_empty(): void
    {
        // Models occasionally write the word rather than the value.
        $result = ExtractionParser::fromArray(['items' => [[
            'type' => 'event', 'title' => 'Trip', 'start' => '2026-09-15',
            'location' => 'null', 'member_hint' => '  ', 'confidence' => 60,
        ]]]);

        $this->assertNull($result->items[0]->location);
        $this->assertNull($result->items[0]->memberHint);
    }

    #[Test]
    public function an_empty_answer_is_a_valid_answer(): void
    {
        $result = ExtractionParser::fromArray(['items' => [], 'summary' => 'Nothing dated here.']);

        $this->assertTrue($result->isEmpty());
        $this->assertSame('Nothing dated here.', $result->summary);
    }
}
