<?php

namespace Tests\Feature\Schools;

use App\Models\BankHoliday;
use App\Models\Household;
use App\Models\Place;
use App\Models\User;
use App\Services\Schools\BankHolidays;
use App\Services\Schools\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Bank holidays, and the days school stops early. */
class BankHolidayTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Place $holyTrinity;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-05-20 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->holyTrinity = Place::create([
            'household_id' => $this->household->id, 'name' => 'Holy Trinity',
            'short_code' => 'HT', 'type' => 'school',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function term(string $from, string $to, ?Place $place = null, ?string $finishes = null): void
    {
        ($place ?? $this->holyTrinity)->schoolDates()->create([
            'kind' => 'term', 'name' => 'Summer 1', 'starts_on' => $from, 'ends_on' => $to,
            'finishes_at' => $finishes,
        ]);
    }

    #[Test]
    public function the_computed_fallback_matches_what_gov_uk_publishes(): void
    {
        // Worked out by hand, these were wrong: easter_date() lands a day
        // early in some years. These are the dates GOV.UK actually lists.
        $computed = app(BankHolidays::class)->computed(2026, 3);

        $this->assertSame('Good Friday', $computed['2026-04-03'] ?? null);
        $this->assertSame('Easter Monday', $computed['2026-04-06'] ?? null);
        $this->assertSame('Good Friday', $computed['2027-03-26'] ?? null);
        $this->assertSame('Good Friday', $computed['2028-04-14'] ?? null);
    }

    #[Test]
    public function a_holiday_landing_at_a_weekend_gets_a_substitute_weekday(): void
    {
        $computed = app(BankHolidays::class)->computed(2027, 1);

        // Christmas Day 2027 is a Saturday, Boxing Day a Sunday.
        $this->assertStringContainsString('substitute', $computed['2027-12-27'] ?? '');
        $this->assertStringContainsString('substitute', $computed['2027-12-28'] ?? '');
    }

    #[Test]
    public function gov_uk_is_preferred_over_the_rules(): void
    {
        Http::fake([BankHolidays::FEED => Http::response([
            'england-and-wales' => ['events' => [
                ['date' => '2026-06-08', 'title' => 'A one-off nobody could compute'],
            ]],
        ])]);

        app(BankHolidays::class)->sync();

        // The rules cannot know about coronations and jubilees, which is
        // exactly why they are second.
        $this->assertDatabaseHas('bank_holidays', [
            'on' => '2026-06-08', 'title' => 'A one-off nobody could compute', 'source' => 'gov',
        ]);
    }

    #[Test]
    public function the_rules_fill_in_when_gov_uk_cannot_be_reached(): void
    {
        Http::fake([BankHolidays::FEED => Http::response('down', 503)]);

        app(BankHolidays::class)->sync();

        $this->assertDatabaseHas('bank_holidays', ['on' => '2026-04-03', 'source' => 'computed']);
    }

    #[Test]
    public function a_confirmed_date_is_not_overwritten_by_a_guess(): void
    {
        BankHoliday::updateOrCreate(['on' => '2026-04-03'], ['title' => 'Good Friday', 'source' => 'gov']);

        Http::fake([BankHolidays::FEED => Http::response('down', 503)]);
        app(BankHolidays::class)->sync();

        $this->assertSame('gov', BankHoliday::where('on', '2026-04-03')->first()->source);
    }

    #[Test]
    public function a_bank_holiday_inside_term_is_shown_as_its_own_band(): void
    {
        BankHoliday::updateOrCreate(['on' => '2026-05-25'], ['title' => 'Spring bank holiday']);
        $this->term('2026-04-20', '2026-07-21');

        $closures = app(SchoolCalendar::class)->closures(
            $this->household,
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-30'),
        );

        $bank = $closures->firstWhere(fn ($c) => $c->isBankHoliday());

        $this->assertNotNull($bank);
        $this->assertSame('Spring bank holiday', $bank->label);
        $this->assertSame('', $bank->code, 'It closes every school, so naming one would mislead.');
        $this->assertNotSame($bank->colour(), '#0d9488', 'Distinct from an ordinary holiday.');
    }

    #[Test]
    public function a_bank_holiday_in_the_holidays_is_not_news(): void
    {
        BankHoliday::updateOrCreate(['on' => '2026-08-31'], ['title' => 'Summer bank holiday']);
        $this->term('2026-04-20', '2026-07-21');

        $closures = app(SchoolCalendar::class)->closures(
            $this->household,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-09-30'),
        );

        $this->assertCount(0, $closures->filter(fn ($c) => $c->isBankHoliday()));
    }

    #[Test]
    public function one_band_covers_every_school(): void
    {
        $sandyGate = Place::create([
            'household_id' => $this->household->id, 'name' => 'Sandy Gate',
            'short_code' => 'SG', 'type' => 'school',
        ]);

        BankHoliday::updateOrCreate(['on' => '2026-05-25'], ['title' => 'Spring bank holiday']);
        $this->term('2026-04-20', '2026-07-21');
        $this->term('2026-04-20', '2026-07-21', $sandyGate);

        $closures = app(SchoolCalendar::class)->closures(
            $this->household,
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-30'),
        );

        // Stamping two school codes on one bank holiday says nothing anyone
        // needed, so the spring one appears once however many schools are shut.
        $this->assertCount(1, $closures->filter(
            fn ($c) => $c->isBankHoliday() && $c->startsOn->toDateString() === '2026-05-25'
        ));
    }

    /* --------------------------- early finishes -------------------------- */

    #[Test]
    public function the_last_day_of_term_carries_its_finish_time(): void
    {
        $this->term('2026-04-20', '2026-05-22', finishes: '13:30');

        $points = app(SchoolCalendar::class)->turningPoints($this->household, CarbonImmutable::parse('2026-05-20'));

        $this->assertSame('Last day of term', $points[0]['label']);
        $this->assertSame('1:30pm', $points[0]['finishes']);
    }

    #[Test]
    public function a_finish_on_the_hour_reads_as_the_hour(): void
    {
        $this->term('2026-04-20', '2026-05-22', finishes: '13:00');

        $points = app(SchoolCalendar::class)->turningPoints($this->household, CarbonImmutable::parse('2026-05-20'));

        $this->assertSame('1pm', $points[0]['finishes']);
    }

    #[Test]
    public function a_one_off_early_finish_is_announced_too(): void
    {
        $this->term('2026-04-20', '2026-07-21');
        $this->holyTrinity->schoolDates()->create([
            'kind' => 'early', 'name' => 'Sports day', 'starts_on' => '2026-05-21',
            'ends_on' => '2026-05-21', 'finishes_at' => '13:30',
        ]);

        $points = app(SchoolCalendar::class)->turningPoints($this->household, CarbonImmutable::parse('2026-05-20'));

        $this->assertSame('Sports day', $points[0]['label']);
        $this->assertSame('1:30pm', $points[0]['finishes']);
    }

    #[Test]
    public function the_wall_says_when_school_finishes(): void
    {
        $this->term('2026-04-20', '2026-05-22', finishes: '13:30');

        Livewire::test('display.wall')
            ->assertSee('Last day of term')
            ->assertSee('finishes 1:30pm');
    }

    /* ------------------------------- copying ------------------------------ */

    #[Test]
    public function one_school_can_take_another_schools_dates(): void
    {
        // Two children on the same county timetable, typed once.
        $sandyGate = Place::create([
            'household_id' => $this->household->id, 'name' => 'Sandy Gate',
            'short_code' => 'SG', 'type' => 'school',
        ]);

        $this->term('2026-04-20', '2026-05-22', finishes: '13:30');
        $this->holyTrinity->schoolDates()->create([
            'kind' => 'inset', 'name' => 'INSET day', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01',
        ]);

        Livewire::test('admin.places')
            ->call('editTerms', $sandyGate->id)
            ->set('copyFrom', (string) $this->holyTrinity->id)
            ->call('copyTermDates')
            ->assertDispatched('saved');

        $copied = $sandyGate->schoolDates()->get();

        $this->assertCount(2, $copied);
        $this->assertSame('1:30pm', $copied->firstWhere('kind', 'term')->finishTime());
        $this->assertNotNull($copied->firstWhere('kind', 'inset'));
    }

    #[Test]
    public function copying_replaces_rather_than_doubles_up(): void
    {
        $sandyGate = Place::create([
            'household_id' => $this->household->id, 'name' => 'Sandy Gate', 'type' => 'school',
        ]);

        $this->term('2026-04-20', '2026-05-22');
        $this->term('2026-01-05', '2026-02-12', $sandyGate);

        Livewire::test('admin.places')
            ->call('editTerms', $sandyGate->id)
            ->set('copyFrom', (string) $this->holyTrinity->id)
            ->call('copyTermDates');

        $this->assertCount(1, $sandyGate->schoolDates()->get());
        $this->assertSame('2026-04-20', $sandyGate->schoolDates()->first()->starts_on->toDateString());
    }

    #[Test]
    public function copying_from_nowhere_says_so(): void
    {
        Livewire::test('admin.places')
            ->call('editTerms', $this->holyTrinity->id)
            ->call('copyTermDates')
            ->assertSee('Choose a school to copy from');
    }

    #[Test]
    public function an_early_finish_is_added_from_the_editor(): void
    {
        Livewire::test('admin.places')
            ->call('editTerms', $this->holyTrinity->id)
            ->set('earlyName', 'Sports day')
            ->set('earlyDate', '2026-06-10')
            ->set('earlyTime', '13:30')
            ->call('addEarlyFinish')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('school_dates', ['kind' => 'early', 'name' => 'Sports day']);
    }
}
