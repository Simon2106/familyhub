<?php

namespace Tests\Feature\Schools;

use App\Models\Household;
use App\Models\Place;
use App\Models\SchoolDate;
use App\Models\User;
use App\Services\Schools\SchoolCalendar;
use App\Services\Schools\SchoolClosure;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Terms are typed in; holidays are the gaps between them.
 */
class TermDatesTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Place $holyTrinity;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-20 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->holyTrinity = Place::create([
            'household_id' => $this->household->id,
            'name' => 'Holy Trinity',
            'short_code' => 'HT',
            'type' => 'school',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function term(string $name, string $from, string $to, ?Place $place = null): SchoolDate
    {
        return ($place ?? $this->holyTrinity)->schoolDates()->create([
            'kind' => 'term', 'name' => $name, 'starts_on' => $from, 'ends_on' => $to,
        ]);
    }

    /** @return Collection<int, SchoolClosure> */
    protected function closures(string $from = '2026-01-01', string $to = '2027-12-31')
    {
        return app(SchoolCalendar::class)->closures(
            $this->household,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    #[Test]
    public function a_short_code_falls_back_to_initials(): void
    {
        $this->assertSame('HT', $this->holyTrinity->code());

        $sandyGate = Place::create([
            'household_id' => $this->household->id, 'name' => 'Sandy Gate', 'type' => 'school',
        ]);

        $this->assertSame('SG', $sandyGate->code());
    }

    #[Test]
    public function the_gap_between_two_terms_is_the_holiday(): void
    {
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');
        $this->term('Autumn 2', '2026-11-02', '2026-12-18');

        $closures = $this->closures();

        $this->assertCount(1, $closures);
        $this->assertSame('Half term', $closures[0]->label);
        $this->assertSame('2026-10-23', $closures[0]->startsOn->toDateString());
        $this->assertSame('2026-11-01', $closures[0]->endsOn->toDateString());
        $this->assertSame('HT', $closures[0]->code);
    }

    #[Test]
    public function a_long_gap_is_a_holiday_rather_than_a_half_term(): void
    {
        $this->term('Summer 2', '2026-06-01', '2026-07-21');
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');

        $this->assertSame('Holidays', $this->closures()[0]->label);
    }

    #[Test]
    public function terms_that_run_together_produce_no_holiday(): void
    {
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');
        $this->term('Autumn 2', '2026-10-23', '2026-12-18');

        $this->assertCount(0, $this->closures());
    }

    #[Test]
    public function nothing_is_shown_during_term_time(): void
    {
        // Term time is the default state; the wall says nothing about it.
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');

        $this->assertCount(0, $this->closures(), 'One term on its own has no gap after it.');
    }

    #[Test]
    public function inset_days_are_shown_in_their_own_right(): void
    {
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');
        $this->holyTrinity->schoolDates()->create([
            'kind' => 'inset', 'name' => 'INSET day', 'starts_on' => '2026-09-04', 'ends_on' => '2026-09-04',
        ]);

        $closures = $this->closures();

        $this->assertCount(1, $closures);
        $this->assertTrue($closures[0]->isInset());
        $this->assertNotSame($closures[0]->colour(), '#0d9488', 'An INSET day earns more attention than August.');
    }

    #[Test]
    public function two_schools_are_kept_apart(): void
    {
        $sandyGate = Place::create([
            'household_id' => $this->household->id, 'name' => 'Sandy Gate', 'short_code' => 'SG', 'type' => 'school',
        ]);

        $this->term('Autumn 1', '2026-09-02', '2026-10-22');
        $this->term('Autumn 2', '2026-11-02', '2026-12-18');
        $this->term('Autumn 1', '2026-09-03', '2026-10-23', $sandyGate);
        $this->term('Autumn 2', '2026-11-03', '2026-12-18', $sandyGate);

        $codes = $this->closures()->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['HT', 'SG'], $codes);
    }

    #[Test]
    public function the_last_day_of_term_is_announced_three_days_out(): void
    {
        // Today is 20 October; term ends on the 22nd.
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');

        $points = app(SchoolCalendar::class)->turningPoints($this->household, CarbonImmutable::parse('2026-10-20'));

        $this->assertCount(1, $points);
        $this->assertSame('Last day of term', $points[0]['label']);
        $this->assertSame('HT', $points[0]['code']);
    }

    #[Test]
    public function going_back_is_announced_too(): void
    {
        $this->term('Autumn 2', '2026-11-02', '2026-12-18');

        $points = app(SchoolCalendar::class)->turningPoints($this->household, CarbonImmutable::parse('2026-10-31'));

        $this->assertSame('Back to school', $points[0]['label']);
    }

    #[Test]
    public function nothing_is_announced_a_fortnight_out(): void
    {
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');

        $points = app(SchoolCalendar::class)->turningPoints($this->household, CarbonImmutable::parse('2026-10-01'));

        $this->assertCount(0, $points);
    }

    #[Test]
    public function the_wall_bands_the_days_school_is_shut(): void
    {
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');
        $this->term('Autumn 2', '2026-11-02', '2026-12-18');

        $closures = Livewire::test('display.wall')->instance()->schoolClosures;

        // Today is 20 Oct, so the fortnight covers the start of half term.
        $this->assertArrayHasKey('2026-10-23', $closures->all());
        $this->assertArrayNotHasKey('2026-10-22', $closures->all(), 'The last day of term is still a school day.');
    }

    #[Test]
    public function the_wall_says_when_term_ends(): void
    {
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');

        Livewire::test('display.wall')
            ->assertSee('Last day of term')
            ->assertSee('HT');
    }

    /* ----------------------------- the editor ---------------------------- */

    #[Test]
    public function terms_are_added_one_row_at_a_time(): void
    {
        Livewire::test('admin.places')
            ->call('editTerms', $this->holyTrinity->id)
            ->set('termName', 'Autumn 1')
            ->set('termStart', '2026-09-02')
            ->set('termEnd', '2026-10-22')
            ->call('addTerm')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('school_dates', [
            'place_id' => $this->holyTrinity->id, 'name' => 'Autumn 1', 'kind' => 'term',
        ]);
    }

    #[Test]
    public function the_next_term_name_is_suggested_so_there_is_less_typing(): void
    {
        $component = Livewire::test('admin.places')->call('editTerms', $this->holyTrinity->id);

        $this->assertSame('Autumn 1', $component->get('termName'));

        $component->set('termStart', '2026-09-02')->set('termEnd', '2026-10-22')->call('addTerm');

        $this->assertSame('Autumn 2', $component->get('termName'));
    }

    #[Test]
    public function a_term_that_ends_before_it_starts_is_refused(): void
    {
        Livewire::test('admin.places')
            ->call('editTerms', $this->holyTrinity->id)
            ->set('termName', 'Autumn 1')
            ->set('termStart', '2026-10-22')
            ->set('termEnd', '2026-09-02')
            ->call('addTerm')
            ->assertHasErrors('termEnd');
    }

    #[Test]
    public function inset_days_are_pasted_as_a_list(): void
    {
        // Which is how a school PDF lists them.
        Livewire::test('admin.places')
            ->call('editTerms', $this->holyTrinity->id)
            ->set('insetDates', '2 Sep 2026, 3 Sep 2026, 5 Jan 2027')
            ->call('addInsetDays');

        $this->assertSame(3, SchoolDate::where('kind', 'inset')->count());
        $this->assertDatabaseHas('school_dates', ['starts_on' => '2027-01-05', 'kind' => 'inset']);
    }

    #[Test]
    public function a_date_that_cannot_be_read_is_named_rather_than_swallowed(): void
    {
        Livewire::test('admin.places')
            ->call('editTerms', $this->holyTrinity->id)
            ->set('insetDates', '2 Sep 2026, the second Tuesday')
            ->call('addInsetDays')
            ->assertSee('Could not read');

        $this->assertSame(1, SchoolDate::where('kind', 'inset')->count(), 'The readable one still went in.');
    }

    #[Test]
    public function the_editor_shows_the_holidays_the_terms_imply(): void
    {
        $this->term('Autumn 1', '2026-09-02', '2026-10-22');
        $this->term('Autumn 2', '2026-11-02', '2026-12-18');

        // So a typo is visible now rather than in half term.
        Livewire::test('admin.places')
            ->call('editTerms', $this->holyTrinity->id)
            ->assertSee('Half term')
            ->assertSee('23 Oct');
    }

    #[Test]
    public function a_school_feed_is_imported_when_there_is_one(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            ."BEGIN:VEVENT\r\nUID:1\r\nDTSTART;VALUE=DATE:20260902\r\nDTEND;VALUE=DATE:20261023\r\n"
            ."SUMMARY:Autumn term\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:2\r\nDTSTART;VALUE=DATE:20260904\r\nDTEND;VALUE=DATE:20260905\r\n"
            ."SUMMARY:INSET day\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        Http::fake(['*' => Http::response($ics)]);
        $this->holyTrinity->update(['term_ical_url' => 'https://school.test/terms.ics']);

        Livewire::test('admin.places')->call('importTerms', $this->holyTrinity->id);

        $this->assertDatabaseHas('school_dates', ['name' => 'Autumn term', 'kind' => 'term', 'source' => 'ical']);
        $this->assertDatabaseHas('school_dates', ['name' => 'INSET day', 'kind' => 'inset', 'source' => 'ical']);
    }

    #[Test]
    public function re_reading_a_feed_leaves_typed_in_dates_alone(): void
    {
        // A school changing its feed must not delete dates read off a PDF.
        $typed = $this->term('Autumn 1', '2026-09-02', '2026-10-22');

        Http::fake(['*' => Http::response("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n")]);
        $this->holyTrinity->update(['term_ical_url' => 'https://school.test/terms.ics']);

        Livewire::test('admin.places')->call('importTerms', $this->holyTrinity->id);

        $this->assertModelExists($typed);
    }

    #[Test]
    public function a_feed_that_is_not_a_calendar_says_so(): void
    {
        Http::fake(['*' => Http::response('<html>Term dates</html>')]);
        $this->holyTrinity->update(['term_ical_url' => 'https://school.test/terms']);

        // The button only exists inside the open panel, so that is the flow.
        Livewire::test('admin.places')
            ->call('editTerms', $this->holyTrinity->id)
            ->call('importTerms', $this->holyTrinity->id)
            ->assertSee('did not return a calendar');
    }
}
