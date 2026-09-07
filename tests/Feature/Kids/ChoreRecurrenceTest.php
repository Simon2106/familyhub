<?php

namespace Tests\Feature\Kids;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Member;
use App\Services\Chores\ChoreBoard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which days a chore falls on. Everything else in Phase 4 reads this, so it is
 * worth pinning down every shape rather than trusting the happy path.
 */
class ChoreRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $joey;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 7 September 2026 through Sunday the 13th.
        CarbonImmutable::setTestNow('2026-09-09 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->joey = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function chore(array $attributes = []): Chore
    {
        return Chore::factory()->create($attributes + [
            'household_id' => $this->household->id,
            'member_id' => $this->joey->id,
        ]);
    }

    /** @return list<string> the weekdays of the week beginning Mon 7 Sep it falls on */
    protected function week(Chore $chore): array
    {
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $date = CarbonImmutable::parse('2026-09-07')->addDays($i);

            if ($chore->occursOn($date)) {
                $days[] = $date->format('D');
            }
        }

        return $days;
    }

    #[Test]
    public function daily_means_every_day_including_the_weekend(): void
    {
        $this->assertSame(
            ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            $this->week($this->chore(['recurrence' => 'daily'])),
        );
    }

    #[Test]
    public function weekdays_means_school_days(): void
    {
        $this->assertSame(
            ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            $this->week($this->chore(['recurrence' => 'weekdays'])),
        );
    }

    #[Test]
    public function specific_days_are_honoured_in_week_order(): void
    {
        $chore = $this->chore(['recurrence' => 'days', 'days' => [7, 2]]);

        $this->assertSame(['Tue', 'Sun'], $this->week($chore));
    }

    #[Test]
    public function weekly_is_one_chosen_day(): void
    {
        $this->assertSame(['Thu'], $this->week($this->chore(['recurrence' => 'weekly', 'days' => [4]])));
    }

    #[Test]
    public function specific_days_with_nothing_chosen_never_falls_due(): void
    {
        // Better silently absent than every day by accident.
        $this->assertSame([], $this->week($this->chore(['recurrence' => 'days', 'days' => []])));
        $this->assertSame([], $this->week($this->chore(['recurrence' => 'days', 'days' => null])));
    }

    #[Test]
    public function nonsense_weekday_numbers_are_ignored(): void
    {
        $chore = $this->chore(['recurrence' => 'days', 'days' => [0, 3, 9, 'x']]);

        $this->assertSame(['Wed'], $this->week($chore));
    }

    #[Test]
    public function a_paused_chore_falls_due_on_nothing(): void
    {
        $this->assertSame([], $this->week($this->chore(['recurrence' => 'daily', 'is_active' => false])));
    }

    #[Test]
    public function a_chore_does_not_appear_before_it_existed_or_after_it_ended(): void
    {
        $chore = $this->chore(['recurrence' => 'daily', 'starts_on' => '2026-09-09', 'ends_on' => '2026-09-11']);

        $this->assertSame(['Wed', 'Thu', 'Fri'], $this->week($chore));
    }

    #[Test]
    public function the_schedule_reads_the_way_a_parent_would_say_it(): void
    {
        $this->assertSame('Every day', $this->chore(['recurrence' => 'daily'])->scheduleLabel());
        $this->assertSame('School days', $this->chore(['recurrence' => 'weekdays'])->scheduleLabel());
        $this->assertSame('Every Thu', $this->chore(['recurrence' => 'weekly', 'days' => [4]])->scheduleLabel());
        $this->assertSame('Tue, Sun', $this->chore(['recurrence' => 'days', 'days' => [2, 7]])->scheduleLabel());
        $this->assertSame('No days chosen', $this->chore(['recurrence' => 'days', 'days' => []])->scheduleLabel());
    }

    #[Test]
    public function the_board_shows_a_chore_nobody_has_touched(): void
    {
        $this->chore(['title' => 'Feed the cat']);

        $slots = app(ChoreBoard::class)->forDay($this->household, CarbonImmutable::parse('2026-09-09'));

        $this->assertCount(1, $slots);
        $this->assertSame('Feed the cat', $slots[0]->chore->title);
        $this->assertFalse($slots[0]->isDone());
        $this->assertNull($slots[0]->instance, 'Looking at the board must not write to it.');
    }

    #[Test]
    public function looking_at_a_week_creates_no_rows_at_all(): void
    {
        $this->chore(['recurrence' => 'daily']);

        app(ChoreBoard::class)->forRange(
            $this->household,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-13'),
        );

        // A fortnight away must not leave a fortnight of accusing empty rows.
        $this->assertDatabaseCount('chore_instances', 0);
    }

    #[Test]
    public function a_week_of_columns_is_read_in_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 6) as $n) {
            $this->chore(['title' => "Chore {$n}"]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(ChoreBoard::class)->forRange(
            $this->household,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-13'),
        );

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Chores and instances, not one per day and certainly not one per cell.
        $this->assertLessThanOrEqual(3, $queries, "A week of chores took {$queries} queries.");
    }
}
