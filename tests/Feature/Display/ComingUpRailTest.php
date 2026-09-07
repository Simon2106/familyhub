<?php

namespace Tests\Feature\Display;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The right-hand rail follows the picked day.
 *
 * Day picking happens in Alpine, so every day's list is rendered and shown or
 * hidden on the client; these assert the markup is there to be shown.
 */
class ComingUpRailTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, so the week has days either side.
        CarbonImmutable::setTestNow('2026-09-09 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create(['calendar_account_id' => $account->id]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function event(string $at, string $title): Event
    {
        return Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => $title,
            'start_at' => $at,
            'end_at' => CarbonImmutable::parse($at)->addHour(),
        ]);
    }

    #[Test]
    public function every_day_of_the_week_gets_its_own_list(): void
    {
        $this->event('2026-09-07 09:00:00', 'Monday only');
        $this->event('2026-09-11 09:00:00', 'Friday only');

        $html = Livewire::test('display.wall')->html();

        foreach (['2026-09-07', '2026-09-11'] as $date) {
            $this->assertStringContainsString('wire:key="rail-'.$date.'"', $html);
        }
    }

    #[Test]
    public function a_days_list_holds_only_that_days_events(): void
    {
        $this->event('2026-09-07 09:00:00', 'Monday only');
        $this->event('2026-09-08 09:00:00', 'Tuesday only');

        $monday = $this->railFor(Livewire::test('display.wall')->html(), '2026-09-07');

        $this->assertStringContainsString('Monday only', $monday);
        $this->assertStringNotContainsString('Tuesday only', $monday,
            'A day headed Monday must not list Tuesday.');
    }

    #[Test]
    public function a_day_with_nothing_on_it_says_so(): void
    {
        $this->event('2026-09-07 09:00:00', 'Monday only');

        $sunday = $this->railFor(Livewire::test('display.wall')->html(), '2026-09-13');

        $this->assertStringContainsString('Nothing on this day.', $sunday);
    }

    #[Test]
    public function the_week_view_still_counts_forward_from_today(): void
    {
        $this->event('2026-09-07 09:00:00', 'Already gone');
        $this->event('2026-09-11 09:00:00', 'Still to come');

        $html = Livewire::test('display.wall')->html();
        $week = mb_substr($html, mb_strpos($html, 'Coming up'));

        $this->assertStringContainsString('Still to come', $week);
        $this->assertStringNotContainsString('Already gone', $week,
            'The week rail looks forward from today, as it always did.');
    }

    #[Test]
    public function todays_list_is_headed_today_rather_than_by_its_date(): void
    {
        $this->event('2026-09-09 09:00:00', 'Happening now');

        $today = $this->railFor(Livewire::test('display.wall')->html(), '2026-09-09');

        $this->assertStringContainsString('Today', $today);
        $this->assertStringNotContainsString('9 September', $today);
    }

    /**
     * The markup for one day's rail, up to where the next one starts.
     *
     * Anchored on the real attribute: Livewire's snapshot at the top of the
     * response repeats every wire:key in escaped JSON, and searching for the
     * bare key finds that instead of the markup.
     */
    protected function railFor(string $html, string $date): string
    {
        $start = mb_strpos($html, 'wire:key="rail-'.$date.'"');

        $this->assertNotFalse($start, "No rail rendered for {$date}.");

        $rest = mb_substr($html, $start + 1);
        $end = mb_strpos($rest, 'wire:key="rail-');

        return $end === false ? $rest : mb_substr($rest, 0, $end);
    }
}
