<?php

namespace Tests\Feature\Kids;

use App\Models\Chore;
use App\Models\ChoreInstance;
use App\Models\Household;
use App\Models\Member;
use App\Models\PointEntry;
use App\Services\Chores\ChoreBoard;
use App\Services\Points\PointsLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ticking, approving, undoing — and what each of those does to the points.
 */
class ChoreApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $joey;

    protected Member $simon;

    protected CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->joey = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true]);
        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
        $this->today = CarbonImmutable::parse('2026-09-09');
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
            'points' => 5,
        ]);
    }

    protected function board(): ChoreBoard
    {
        return app(ChoreBoard::class);
    }

    protected function balance(): int
    {
        return app(PointsLedger::class)->balanceFor($this->joey);
    }

    #[Test]
    public function ticking_a_trusted_chore_pays_immediately(): void
    {
        $instance = $this->board()->complete($this->chore(), $this->today, $this->joey);

        $this->assertTrue($instance->isDone());
        $this->assertTrue($instance->isEarned());
        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function a_chore_that_wants_approval_pays_nothing_until_it_has_it(): void
    {
        $chore = $this->chore()->fill(['needs_approval' => true]);
        $chore->save();

        $instance = $this->board()->complete($chore, $this->today, $this->joey);

        $this->assertTrue($instance->isDone());
        $this->assertTrue($instance->isAwaitingApproval());
        $this->assertSame(0, $this->balance(), 'Nothing is earned before a grown-up has looked.');

        $this->board()->approve($instance, $this->simon);

        $this->assertSame(5, $this->balance());
        $this->assertSame($this->simon->id, $instance->fresh()->approved_by_member_id);
    }

    #[Test]
    public function approving_something_nobody_ticked_records_that_it_was_done(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = $this->board()->instanceFor($chore, $this->today);

        $approved = $this->board()->approve($instance, $this->simon);

        $this->assertTrue($approved->isDone(), 'A half-approved, never-done state would be a lie.');
        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function withdrawing_approval_takes_the_points_back(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = $this->board()->complete($chore, $this->today, $this->joey);

        $this->board()->approve($instance, $this->simon);
        $this->assertSame(5, $this->balance());

        $this->board()->unapprove($instance->fresh());

        $this->assertSame(0, $this->balance());
        $this->assertTrue($instance->fresh()->isDone(), 'Withdrawing approval does not deny it was done.');
    }

    #[Test]
    public function unticking_clears_the_tick_and_the_points(): void
    {
        $instance = $this->board()->complete($this->chore(), $this->today, $this->joey);

        $this->board()->uncomplete($instance);

        $this->assertFalse($instance->fresh()->isDone());
        $this->assertSame(0, $this->balance());
    }

    #[Test]
    public function what_a_chore_was_worth_on_the_day_is_locked_in(): void
    {
        $chore = $this->chore(['points' => 5]);
        $instance = $this->board()->complete($chore, $this->today, $this->joey);

        // A parent decides feeding the cat is worth more from now on.
        $chore->update(['points' => 50]);

        $this->assertSame(5, $instance->fresh()->points);
        $this->assertSame(5, $this->balance(), 'Re-pricing a chore must not restate what was already earned.');
    }

    #[Test]
    public function reassigning_a_chore_does_not_rewrite_who_earned_last_weeks_points(): void
    {
        $sienna = Member::factory()->create(['household_id' => $this->household->id, 'is_child' => true]);
        $chore = $this->chore();

        $this->board()->complete($chore, $this->today, $this->joey);

        $chore->update(['member_id' => $sienna->id]);

        $this->assertSame(5, app(PointsLedger::class)->balanceFor($this->joey));
        $this->assertSame(0, app(PointsLedger::class)->balanceFor($sienna));
    }

    #[Test]
    public function a_chore_for_nobody_in_particular_pays_nobody(): void
    {
        $chore = $this->chore(['member_id' => null]);

        $this->board()->complete($chore, $this->today, $this->joey);

        $this->assertSame(0, PointEntry::count());
    }

    #[Test]
    public function ticking_twice_creates_one_instance_and_pays_once(): void
    {
        $chore = $this->chore();

        // Two thumbs on the same tile on a wall-mounted screen.
        $this->board()->complete($chore, $this->today, $this->joey);
        $this->board()->complete($chore, $this->today, $this->joey);

        $this->assertSame(1, ChoreInstance::count());
        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function approving_twice_pays_once(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = $this->board()->complete($chore, $this->today, $this->joey);

        $this->board()->approve($instance, $this->simon);
        $this->board()->approve($instance->fresh(), $this->simon);

        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function ticking_untickingand_ticking_again_settles_on_the_right_number(): void
    {
        $chore = $this->chore();

        $instance = $this->board()->complete($chore, $this->today, $this->joey);
        $this->board()->uncomplete($instance);
        $this->board()->complete($chore, $this->today, $this->joey);
        $instance = $this->board()->complete($chore, $this->today, $this->joey);
        $this->board()->uncomplete($instance);
        $this->board()->complete($chore, $this->today, $this->joey);

        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function the_ledger_is_append_only(): void
    {
        $chore = $this->chore();
        $instance = $this->board()->complete($chore, $this->today, $this->joey);

        $this->board()->uncomplete($instance);

        // The award stands and a reversal sits beside it. A child can see
        // where their stars went, which is the entire point.
        $this->assertSame(2, PointEntry::count());
        $this->assertSame([5, -5], PointEntry::orderBy('id')->pluck('points')->all());
        $this->assertSame(['award', 'reversal'], PointEntry::orderBy('id')->pluck('kind')->all());
        $this->assertSame(0, $this->balance());
    }

    #[Test]
    public function reversing_something_already_reversed_writes_nothing_more(): void
    {
        $chore = $this->chore();
        $instance = $this->board()->complete($chore, $this->today, $this->joey);

        $this->board()->uncomplete($instance);
        app(PointsLedger::class)->reverseFor($instance->fresh());

        $this->assertSame(2, PointEntry::count());
        $this->assertSame(0, $this->balance());
    }

    #[Test]
    public function a_balance_is_the_sum_of_the_rows_and_nothing_else(): void
    {
        $ledger = app(PointsLedger::class);

        $this->board()->complete($this->chore(['title' => 'Cat']), $this->today, $this->joey);
        $this->board()->complete($this->chore(['title' => 'Bins', 'points' => 3]), $this->today, $this->joey);
        $ledger->adjust($this->joey, -2, 'Left the bathroom flooded');

        $this->assertSame(6, $ledger->balanceFor($this->joey));
        $this->assertSame(6, (int) PointEntry::where('member_id', $this->joey->id)->sum('points'));
    }

    #[Test]
    public function the_weekly_summary_counts_awards_net_of_reversals(): void
    {
        $ledger = app(PointsLedger::class);
        $chore = $this->chore();

        $instance = $this->board()->complete($chore, $this->today, $this->joey);
        $this->board()->uncomplete($instance);
        $this->board()->complete($this->chore(['title' => 'Bins', 'points' => 3]), $this->today, $this->joey);

        $earned = $ledger->earnedBetween($this->joey, '2026-09-07 00:00:00', '2026-09-13 23:59:59');

        $this->assertSame(3, $earned, 'Ticked and un-ticked in the same week is worth nothing, not double.');
    }
}
