<?php

namespace Tests\Feature\Kids;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Member;
use App\Models\Reward;
use App\Models\User;
use App\Services\Chores\ChoreBoard;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardShop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The grown-ups' side: deciding, undoing, and the week so far. */
class ParentViewTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $joey;

    protected Member $simon;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, so the week already has days behind it.
        CarbonImmutable::setTestNow('2026-09-09 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);
        $this->simon = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Simon', 'is_child' => false,
        ]);

        $this->actingAs(User::factory()->create([
            'household_id' => $this->household->id,
            'member_id' => $this->simon->id,
        ]));
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

    protected function balance(): int
    {
        return app(PointsLedger::class)->balanceFor($this->joey);
    }

    #[Test]
    public function the_page_requires_a_signed_in_parent(): void
    {
        auth()->logout();

        $this->get('/app/kids')->assertRedirect('/login');
    }

    #[Test]
    public function todays_chores_are_listed_by_child(): void
    {
        $this->chore(['title' => 'Feed the cat']);
        $this->chore(['title' => 'Weekend only', 'recurrence' => 'days', 'days' => [6, 7]]);

        Livewire::test('kids.parent')
            ->assertSee('Joey')
            ->assertSee('Feed the cat')
            ->assertDontSee('Weekend only');
    }

    #[Test]
    public function a_parent_ticks_without_a_pin(): void
    {
        $chore = $this->chore(['title' => 'Feed the cat']);

        Livewire::test('kids.parent')
            ->call('tick', $chore->id)
            ->assertNotDispatched('need-pin');

        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function a_parent_undoes_a_tick_without_a_pin(): void
    {
        $chore = $this->chore();
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')
            ->call('undo', $chore->id)
            ->assertNotDispatched('need-pin');

        $this->assertSame(0, $this->balance());
    }

    #[Test]
    public function chores_waiting_to_be_checked_are_led_with(): void
    {
        $chore = $this->chore(['title' => 'Tidy your room', 'needs_approval' => true]);
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')
            ->assertSee('Waiting for you')
            ->assertSee('Tidy your room');
    }

    #[Test]
    public function approving_from_here_pays_and_records_who_approved(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')->call('approve', $chore->id);

        $this->assertSame(5, $this->balance());
        $this->assertSame($this->simon->id, $instance->fresh()->approved_by_member_id);
    }

    #[Test]
    public function approving_a_chore_nobody_ticked_records_it_as_done(): void
    {
        $chore = $this->chore(['needs_approval' => true]);

        Livewire::test('kids.parent')->call('approve', $chore->id);

        $this->assertSame(5, $this->balance());
        $this->assertDatabaseCount('chore_instances', 1);
    }

    #[Test]
    public function pending_reward_requests_can_be_granted(): void
    {
        app(PointsLedger::class)->adjust($this->joey, 50, 'Starting balance');
        $reward = Reward::factory()->create(['household_id' => $this->household->id, 'cost' => 20]);
        $request = app(RewardShop::class)->request($this->joey, $reward);

        Livewire::test('kids.parent')
            ->assertSee($reward->name)
            ->call('grant', $request->id);

        $this->assertTrue($request->fresh()->isGranted());
        $this->assertSame(30, $this->balance());
    }

    #[Test]
    public function a_request_can_be_declined_without_charging_anything(): void
    {
        app(PointsLedger::class)->adjust($this->joey, 50, 'Starting balance');
        $reward = Reward::factory()->create(['household_id' => $this->household->id, 'cost' => 20]);
        $request = app(RewardShop::class)->request($this->joey, $reward);

        Livewire::test('kids.parent')->call('decline', $request->id);

        $this->assertSame('declined', $request->fresh()->status);
        $this->assertSame(50, $this->balance());
    }

    #[Test]
    public function a_grant_that_can_no_longer_be_afforded_says_so(): void
    {
        app(PointsLedger::class)->adjust($this->joey, 20, 'Starting balance');
        $reward = Reward::factory()->create(['household_id' => $this->household->id, 'cost' => 20]);
        $request = app(RewardShop::class)->request($this->joey, $reward);

        app(PointsLedger::class)->adjust($this->joey, -15, 'Broke a window');

        Livewire::test('kids.parent')
            ->call('grant', $request->id)
            ->assertSee('no longer has enough points');

        $this->assertTrue($request->fresh()->isPending());
    }

    #[Test]
    public function the_weekly_summary_counts_the_week_so_far(): void
    {
        $chore = $this->chore(['points' => 5]);
        $board = app(ChoreBoard::class);

        // Monday and Wednesday of this week; the Sunday before must not count.
        $board->complete($chore, CarbonImmutable::parse('2026-09-07'), $this->joey);
        $board->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        CarbonImmutable::setTestNow('2026-09-06 12:00:00');
        $board->complete($this->chore(['title' => 'Last week']), CarbonImmutable::parse('2026-09-06'), $this->joey);
        CarbonImmutable::setTestNow('2026-09-09 07:00:00');

        $row = Livewire::test('kids.parent')->instance()->summary->first();

        $this->assertSame(10, $row['earned'], 'Only this week counts towards the weekly total.');
        $this->assertSame(15, $row['balance'], 'The balance is everything ever earned.');
        $this->assertSame(2, $row['done']);
    }

    #[Test]
    public function the_summary_shows_money_only_when_allowance_is_on(): void
    {
        $chore = $this->chore(['points' => 20]);
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')->assertDontSee('£');

        $this->household->setAllowance(true, 5);
        Once::flush();

        Livewire::test('kids.parent')->assertSee('£1.00');
    }

    #[Test]
    public function a_household_with_no_children_says_so(): void
    {
        $this->joey->delete();

        Livewire::test('kids.parent')->assertSee('No children set up yet.');
    }

    #[Test]
    public function the_parent_view_does_not_query_once_per_chore(): void
    {
        foreach (range(1, 3) as $n) {
            $this->chore(['title' => "Chore {$n}"]);
        }

        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test('kids.parent');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $measure();
        $withThree = $measure();

        foreach (range(4, 9) as $n) {
            $this->chore(['title' => "Chore {$n}"]);
        }

        $this->assertSame($withThree, $measure(), 'Query count grew with the number of chores.');
    }
}
