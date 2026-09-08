<?php

namespace Tests\Feature\Kids;

use App\Models\Household;
use App\Models\Member;
use App\Models\PointEntry;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\User;
use App\Services\Points\PointsLedger;
use App\Services\Points\RewardShop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Spending points: who may ask, who decides, and what the ledger records. */
class RewardTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $joey;

    protected Member $simon;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Joey',
            'is_child' => true,
            'pin' => '1234',
        ]);
        $this->simon = Member::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Simon',
            'is_child' => false,
            'pin' => '9876',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function reward(array $attributes = []): Reward
    {
        return Reward::factory()->create($attributes + ['household_id' => $this->household->id, 'cost' => 20]);
    }

    protected function give(int $points): void
    {
        app(PointsLedger::class)->adjust($this->joey, $points, 'Starting balance');
    }

    protected function shop(): RewardShop
    {
        return app(RewardShop::class);
    }

    protected function balance(): int
    {
        return app(PointsLedger::class)->balanceFor($this->joey);
    }

    #[Test]
    public function a_request_takes_nothing_until_a_grown_up_says_yes(): void
    {
        $this->give(50);

        $this->shop()->request($this->joey, $this->reward());

        $this->assertSame(50, $this->balance(), 'Asking is not spending.');
        $this->assertSame(1, Redemption::pending()->count());
    }

    #[Test]
    public function granting_it_moves_the_points(): void
    {
        $this->give(50);
        $redemption = $this->shop()->request($this->joey, $this->reward());

        $granted = $this->shop()->grant($redemption, $this->simon);

        $this->assertTrue($granted->isGranted());
        $this->assertSame(30, $this->balance());
        $this->assertSame($this->simon->id, $granted->decided_by_member_id);
    }

    #[Test]
    public function declining_it_leaves_the_balance_untouched(): void
    {
        $this->give(50);
        $redemption = $this->shop()->request($this->joey, $this->reward());

        $this->shop()->decline($redemption, $this->simon);

        $this->assertSame(50, $this->balance());
        $this->assertSame(0, PointEntry::where('kind', 'redemption')->count());
    }

    #[Test]
    public function you_cannot_ask_for_something_you_cannot_afford(): void
    {
        $this->give(5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('costs 20 points and you have 5');

        $this->shop()->request($this->joey, $this->reward());
    }

    #[Test]
    public function affordability_is_checked_again_when_it_is_granted(): void
    {
        $this->give(20);
        $redemption = $this->shop()->request($this->joey, $this->reward());

        // Points can go between the asking and the answering.
        app(PointsLedger::class)->adjust($this->joey, -15, 'Broke a window');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer has enough points');

        $this->shop()->grant($redemption, $this->simon);
    }

    #[Test]
    public function granting_the_same_request_twice_only_charges_once(): void
    {
        $this->give(50);
        $redemption = $this->shop()->request($this->joey, $this->reward());

        $this->shop()->grant($redemption, $this->simon);
        $this->shop()->grant($redemption->fresh(), $this->simon);

        $this->assertSame(30, $this->balance());
    }

    #[Test]
    public function cancelling_a_grant_gives_the_points_back_as_a_reversal(): void
    {
        $this->give(50);
        $redemption = $this->shop()->request($this->joey, $this->reward());
        $this->shop()->grant($redemption, $this->simon);

        $this->shop()->ungrant($redemption->fresh());

        $this->assertSame(50, $this->balance());

        // Three lines, not one edited one: the ledger is a record of what
        // happened, which is the point of showing it to a child.
        $this->assertSame([50, -20, 20], PointEntry::orderBy('id')->pluck('points')->all());
    }

    #[Test]
    public function history_survives_the_catalogue_changing(): void
    {
        $this->give(50);
        $reward = $this->reward(['name' => 'An hour of screen time']);
        $redemption = $this->shop()->request($this->joey, $reward);
        $this->shop()->grant($redemption, $this->simon);

        $reward->delete();

        $this->assertSame('An hour of screen time', $redemption->fresh()->name);
        $this->assertSame(20, $redemption->fresh()->cost);
        $this->assertSame(30, $this->balance());
    }

    #[Test]
    public function re_pricing_a_reward_does_not_restate_what_it_cost(): void
    {
        $this->give(50);
        $reward = $this->reward(['cost' => 20]);
        $redemption = $this->shop()->request($this->joey, $reward);

        $reward->update(['cost' => 45]);

        $this->shop()->grant($redemption->fresh(), $this->simon);

        $this->assertSame(30, $this->balance());
    }

    #[Test]
    public function asking_to_spend_needs_the_childs_own_pin(): void
    {
        $this->give(50);
        $reward = $this->reward();

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->call('askFor', $reward->id)
            ->assertDispatched('need-pin');

        // Asking for the PIN is not the same as having it.
        $this->assertSame(0, Redemption::count());
    }

    #[Test]
    public function the_right_pin_creates_the_request(): void
    {
        $this->give(50);
        $reward = $this->reward();

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->call('pinAccepted', 'redeem', $reward->id);

        $this->assertSame(1, Redemption::pending()->count());
        $this->assertSame(50, $this->balance());
    }

    #[Test]
    public function a_request_beyond_your_means_says_so_rather_than_failing(): void
    {
        $this->give(5);
        $reward = $this->reward();

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->call('pinAccepted', 'redeem', $reward->id)
            ->assertSee('costs 20 points and you have 5');

        $this->assertSame(0, Redemption::count());
    }

    #[Test]
    public function granting_at_the_wall_takes_any_grown_ups_pin(): void
    {
        // The wall has no session, which is the whole reason it asks.
        auth()->logout();

        $this->give(50);
        $redemption = $this->shop()->request($this->joey, $this->reward());

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->call('grant', $redemption->id)
            ->assertDispatched('need-adult-pin');

        $this->assertSame(50, $this->balance(), 'Asking is not granting.');
    }

    #[Test]
    public function the_keypad_accepts_any_adults_pin_for_a_grant(): void
    {
        Member::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Jenna',
            'is_child' => false,
            'pin' => '5555',
        ]);

        // Whichever parent is standing in the kitchen should be able to say yes.
        Livewire::test('kids.pin')
            ->call('askAnyAdult', 'grant-redemption', 7)
            ->call('press', '5')->call('press', '5')->call('press', '5')->call('press', '5')
            ->assertDispatched('pin-accepted', action: 'grant-redemption', subject: 7);
    }

    #[Test]
    public function a_childs_pin_will_not_grant_a_reward(): void
    {
        Livewire::test('kids.pin')
            ->call('askAnyAdult', 'grant-redemption', 7)
            ->call('press', '1')->call('press', '2')->call('press', '3')->call('press', '4')
            ->assertNotDispatched('pin-accepted')
            ->assertSee('That is not the right PIN.');
    }

    #[Test]
    public function a_child_cannot_grant_another_childs_request(): void
    {
        $sienna = Member::factory()->create(['household_id' => $this->household->id, 'is_child' => true]);
        app(PointsLedger::class)->adjust($sienna, 50, 'Starting balance');
        $theirs = $this->shop()->request($sienna, $this->reward());

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->call('pinAccepted', 'grant-redemption', $theirs->id);

        $this->assertTrue($theirs->fresh()->isPending());
    }

    #[Test]
    public function the_shop_only_offers_what_is_currently_offered(): void
    {
        $this->give(100);
        $this->reward(['name' => 'Screen time']);
        $this->reward(['name' => 'Withdrawn', 'is_active' => false]);

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->assertSee('Screen time')
            ->assertDontSee('Withdrawn');
    }

    #[Test]
    public function allowance_mode_is_off_until_it_is_turned_on(): void
    {
        $this->give(100);
        $this->reward();

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->assertDontSee('worth £');

        $this->household->setAllowance(true, 5);
        Once::flush();

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->assertSee('worth £5.00');
    }

    #[Test]
    public function a_parent_previewing_the_wall_from_their_phone_is_not_asked(): void
    {
        // Still signed in, so still a parent — whatever screen they are looking at.
        $this->give(50);
        $redemption = $this->shop()->request($this->joey, $this->reward());

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->call('grant', $redemption->id)
            ->assertNotDispatched('need-adult-pin');

        $this->assertTrue($redemption->fresh()->isGranted());
    }
}
