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
            ->call('toggle', $chore->id, '2026-09-09')
            ->assertNotDispatched('need-pin');

        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function a_parent_undoes_a_tick_without_a_pin(): void
    {
        $chore = $this->chore();
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')
            ->call('toggle', $chore->id, '2026-09-09')
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

        Livewire::test('kids.parent')->call('approve', $chore->id, '2026-09-09');

        $this->assertSame(5, $this->balance());
        $this->assertSame($this->simon->id, $instance->fresh()->approved_by_member_id);
    }

    #[Test]
    public function approving_a_chore_nobody_ticked_records_it_as_done(): void
    {
        $chore = $this->chore(['needs_approval' => true]);

        Livewire::test('kids.parent')->call('approve', $chore->id, '2026-09-09');

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

    #[Test]
    public function a_chore_ticked_yesterday_still_reaches_the_approval_queue(): void
    {
        // The reported bug: the queue only looked at today, so a Sunday-evening
        // tick was never shown to anybody and the child waited forever.
        $chore = $this->chore(['title' => 'Take the bag upstairs', 'needs_approval' => true]);
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-08'), $this->joey);

        Livewire::test('kids.parent')
            ->assertSee('Waiting for you')
            ->assertSee('Take the bag upstairs')
            ->assertSee('yesterday');
    }

    #[Test]
    public function a_chore_from_the_queue_can_be_approved_whatever_day_it_was_done(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-08'), $this->joey);

        Livewire::test('kids.parent')->call('approveInstance', $instance->id);

        $this->assertTrue($instance->fresh()->isApproved());
        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function a_chore_from_the_queue_can_be_undone(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-08'), $this->joey);

        Livewire::test('kids.parent')->call('undoInstance', $instance->id);

        $this->assertFalse($instance->fresh()->isDone());
        $this->assertSame(0, $this->balance());
    }

    #[Test]
    public function the_queue_reaches_back_but_not_forever(): void
    {
        $chore = $this->chore(['title' => 'Ancient history', 'needs_approval' => true]);

        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-08-20'), $this->joey);
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-06-01'), $this->joey);

        // Three weeks back is still worth chasing; three months is history.
        $awaiting = Livewire::test('kids.parent')->instance()->awaiting;

        $this->assertSame(['2026-08-20'], $awaiting->map(fn ($i) => $i->on->toDateString())->all());
    }

    #[Test]
    public function an_approved_chore_leaves_the_queue(): void
    {
        $chore = $this->chore(['title' => 'Take the bag upstairs', 'needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-08'), $this->joey);

        Livewire::test('kids.parent')
            ->assertSee('Take the bag upstairs')
            ->call('approveInstance', $instance->id)
            ->assertDontSee('Waiting for you');
    }

    #[Test]
    public function a_chore_that_needs_no_checking_never_joins_the_queue(): void
    {
        $chore = $this->chore(['title' => 'Feed the cat']);
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-08'), $this->joey);

        Livewire::test('kids.parent')->assertDontSee('Waiting for you');
    }

    #[Test]
    public function a_childs_card_opens_a_day_picker_for_the_week(): void
    {
        $chore = $this->chore(['title' => 'Monday only', 'recurrence' => 'days', 'days' => [1]]);

        $component = Livewire::test('kids.parent')
            ->assertDontSee('Monday only')
            ->call('togglePicker', $this->joey->id)
            ->call('pickDay', $this->joey->id, '2026-09-07');

        $component->assertSee('Monday only');
        $this->assertSame('2026-09-07', $component->instance()->dateFor($this->joey->id));
    }

    #[Test]
    public function a_chore_can_be_approved_on_a_day_that_is_not_today(): void
    {
        $chore = $this->chore(['needs_approval' => true, 'recurrence' => 'daily']);
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-07'), $this->joey);

        Livewire::test('kids.parent')
            ->call('pickDay', $this->joey->id, '2026-09-07')
            ->call('approve', $chore->id, '2026-09-07');

        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function a_day_outside_this_week_is_refused(): void
    {
        // The date comes from the browser, so it is not to be trusted.
        $chore = $this->chore(['recurrence' => 'daily']);

        $component = Livewire::test('kids.parent')
            ->call('pickDay', $this->joey->id, '2026-12-25')
            ->call('toggle', $chore->id, '2026-12-25');

        $this->assertSame('2026-09-09', $component->instance()->dateFor($this->joey->id));
        $this->assertSame(0, $this->balance());
    }

    #[Test]
    public function each_childs_day_is_picked_independently(): void
    {
        $sienna = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Sienna', 'is_child' => true,
        ]);

        $component = Livewire::test('kids.parent')->call('pickDay', $this->joey->id, '2026-09-07');

        $this->assertSame('2026-09-07', $component->instance()->dateFor($this->joey->id));
        $this->assertSame('2026-09-09', $component->instance()->dateFor($sienna->id));
    }

    #[Test]
    public function held_back_points_are_labelled_rather_than_shown_as_nothing(): void
    {
        // The reported confusion: one chore done, nothing saved, no explanation.
        $chore = $this->chore(['needs_approval' => true]);
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-08'), $this->joey);

        $component = Livewire::test('kids.parent');
        $row = $component->instance()->summary->first();

        $this->assertSame(1, $row['done']);
        $this->assertSame(0, $row['earned']);
        $this->assertSame(5, $row['pending']);

        $component->assertSee('5 pending your check');
    }

    #[Test]
    public function nothing_is_pending_once_it_has_been_checked(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-08'), $this->joey);
        app(ChoreBoard::class)->approve($instance, $this->simon);

        $row = Livewire::test('kids.parent')->instance()->summary->first();

        $this->assertSame(0, $row['pending']);
        $this->assertSame(5, $row['earned']);
    }

    #[Test]
    public function a_chore_taken_on_trust_is_never_pending(): void
    {
        $chore = $this->chore();
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        $row = Livewire::test('kids.parent')->instance()->summary->first();

        $this->assertSame(0, $row['pending']);
        $this->assertSame(5, $row['earned']);
    }

    #[Test]
    public function the_week_total_counts_every_day_including_sunday(): void
    {
        $chore = $this->chore(['recurrence' => 'daily']);
        $board = app(ChoreBoard::class);

        $board->complete($chore, CarbonImmutable::parse('2026-09-07'), $this->joey);
        $board->complete($chore, CarbonImmutable::parse('2026-09-13'), $this->joey);

        $row = Livewire::test('kids.parent')->instance()->summary->first();

        $this->assertSame(2, $row['done'], 'Sunday is part of the week.');
    }

    #[Test]
    public function the_waiting_list_puts_the_oldest_first(): void
    {
        // What has been waiting longest is what is most likely forgotten.
        $chore = $this->chore(['needs_approval' => true, 'recurrence' => 'daily']);
        $board = app(ChoreBoard::class);

        $board->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);
        $board->complete($chore, CarbonImmutable::parse('2026-09-07'), $this->joey);
        $board->complete($chore, CarbonImmutable::parse('2026-09-08'), $this->joey);

        $dates = Livewire::test('kids.parent')->instance()
            ->awaiting->map(fn ($i) => $i->on->toDateString())->all();

        $this->assertSame(['2026-09-07', '2026-09-08', '2026-09-09'], $dates);
    }

    #[Test]
    public function a_signed_in_parent_is_never_asked_to_prove_it_again(): void
    {
        // They proved it by signing in. Asking twice is theatre.
        $this->simon->update(['pin' => '9876']);

        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')
            ->call('approveInstance', $instance->id)
            ->assertNotDispatched('need-adult-pin');

        $this->assertTrue($instance->fresh()->isApproved());
        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function the_wall_is_asked_because_it_has_no_session(): void
    {
        $this->simon->update(['pin' => '9876']);
        auth()->logout();

        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')
            ->call('approveInstance', $instance->id)
            ->assertDispatched('need-adult-pin', action: 'approve-chore', subject: $instance->id);

        $this->assertFalse($instance->fresh()->isApproved(), 'Asking is not approving.');
    }

    #[Test]
    public function the_pin_lets_the_approval_through(): void
    {
        $this->simon->update(['pin' => '9876']);

        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')->call('pinAccepted', 'approve-chore', $instance->id);

        $this->assertTrue($instance->fresh()->isApproved());
        $this->assertSame(5, $this->balance());
    }

    #[Test]
    public function granting_a_reward_follows_the_same_rule(): void
    {
        $this->simon->update(['pin' => '9876']);
        app(PointsLedger::class)->adjust($this->joey, 50, 'Starting balance');

        $reward = Reward::factory()->create(['household_id' => $this->household->id, 'cost' => 20]);
        $request = app(RewardShop::class)->request($this->joey, $reward);

        // Signed in: straight through.
        Livewire::test('kids.parent')
            ->call('grant', $request->id)
            ->assertNotDispatched('need-adult-pin');

        $this->assertTrue($request->fresh()->isGranted());
    }

    #[Test]
    public function a_household_with_no_adult_pin_is_not_locked_out_of_its_own_approvals(): void
    {
        // Nothing to check against, so refusing would simply stop approvals
        // working until somebody went and set a PIN.
        $this->assertNull($this->simon->pin);

        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);

        Livewire::test('kids.parent')
            ->call('approveInstance', $instance->id)
            ->assertNotDispatched('need-adult-pin');

        $this->assertTrue($instance->fresh()->isApproved());
    }

    #[Test]
    public function a_childs_card_opens_their_points_history(): void
    {
        Livewire::test('kids.parent')->assertSeeHtml('show-ledger');
    }

    #[Test]
    public function the_history_is_the_same_one_the_child_sees(): void
    {
        app(PointsLedger::class)->adjust($this->joey, 12, 'Birthday');

        Livewire::test('kids.ledger')
            ->call('show', $this->joey->id)
            ->assertSee('Birthday')
            ->assertSee('12');
    }
}
