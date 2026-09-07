<?php

namespace Tests\Feature\Kids;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Chores\ChoreBoard;
use App\Services\Points\PointsLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The child-facing view, and the rules about when a PIN is asked for. */
class MyDayTest extends TestCase
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
        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
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

    protected function open(): Testable
    {
        return Livewire::test('kids.my-day')->call('show', $this->joey->id, '2026-09-09');
    }

    #[Test]
    public function a_childs_day_lists_the_chores_that_are_theirs(): void
    {
        $this->chore(['title' => 'Feed the cat']);
        $this->chore(['title' => 'Not mine', 'member_id' => $this->simon->id]);

        $this->open()
            ->assertSee('Joey')
            ->assertSee('Feed the cat')
            ->assertDontSee('Not mine');
    }

    #[Test]
    public function ticking_a_chore_never_asks_for_a_pin(): void
    {
        $chore = $this->chore(['title' => 'Feed the cat']);

        $this->open()
            ->call('tick', $chore->id)
            ->assertNotDispatched('need-pin');

        $this->assertSame(5, app(PointsLedger::class)->balanceFor($this->joey));
    }

    #[Test]
    public function undoing_your_own_tick_is_free(): void
    {
        $chore = $this->chore();

        $this->open()
            ->call('tick', $chore->id)
            ->call('tick', $chore->id)
            ->assertNotDispatched('need-pin');

        $this->assertSame(0, app(PointsLedger::class)->balanceFor($this->joey));
    }

    #[Test]
    public function undoing_something_a_grown_up_checked_asks_for_the_pin(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);
        app(ChoreBoard::class)->approve($instance, $this->simon);

        $this->open()
            ->call('tick', $chore->id)
            ->assertDispatched('need-pin');

        // Still done: asking is not the same as doing.
        $this->assertTrue($instance->fresh()->isDone());
        $this->assertSame(5, app(PointsLedger::class)->balanceFor($this->joey));
    }

    #[Test]
    public function the_right_pin_lets_the_undo_through(): void
    {
        $chore = $this->chore(['needs_approval' => true]);
        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);
        app(ChoreBoard::class)->approve($instance, $this->simon);

        $this->open()->call('pinAccepted', 'undo-chore', $chore->id);

        $this->assertFalse($instance->fresh()->isDone());
        $this->assertSame(0, app(PointsLedger::class)->balanceFor($this->joey));
    }

    #[Test]
    public function a_chore_awaiting_a_grown_up_says_so(): void
    {
        $chore = $this->chore(['title' => 'Tidy your room', 'needs_approval' => true]);

        $this->open()
            ->call('tick', $chore->id)
            ->assertSee('Waiting to be checked');
    }

    #[Test]
    public function finishing_everything_is_made_a_fuss_of(): void
    {
        $chore = $this->chore();

        $this->open()
            ->assertDontSee('All done!')
            ->call('tick', $chore->id)
            ->assertSee('All done!');
    }

    #[Test]
    public function a_day_with_nothing_on_it_does_not_claim_to_be_all_done(): void
    {
        $this->open()
            ->assertSee('Nothing to do today.')
            ->assertDontSee('All done!');
    }

    #[Test]
    public function the_running_points_total_is_shown(): void
    {
        app(PointsLedger::class)->adjust($this->joey, 42, 'Birthday');

        $this->open()->assertSee('42');
    }

    #[Test]
    public function a_child_cannot_tick_a_chore_that_is_not_theirs(): void
    {
        $notTheirs = $this->chore(['member_id' => $this->simon->id]);

        $this->expectException(ModelNotFoundException::class);

        $this->open()->call('tick', $notTheirs->id);
    }

    #[Test]
    public function the_keypad_only_accepts_the_right_pin(): void
    {
        Livewire::test('kids.pin')
            ->call('ask', $this->joey->id, 'undo-chore', 7)
            ->call('press', '9')->call('press', '9')->call('press', '9')->call('press', '9')
            ->assertNotDispatched('pin-accepted')
            ->assertSee('That is not the right PIN.')
            ->call('press', '1')->call('press', '2')->call('press', '3')->call('press', '4')
            ->assertDispatched('pin-accepted', action: 'undo-chore', subject: 7);
    }

    #[Test]
    public function the_wall_shows_a_childs_chores_in_their_column(): void
    {
        $this->chore(['title' => 'Feed the cat']);

        Livewire::test('display.wall')->assertSee('Feed the cat');
    }

    #[Test]
    public function the_wall_column_is_not_written_to_by_being_looked_at(): void
    {
        $this->chore();

        Livewire::test('display.wall');

        $this->assertDatabaseCount('chore_instances', 0);
    }

    #[Test]
    public function the_wall_does_not_query_once_per_chore(): void
    {
        foreach (range(1, 3) as $n) {
            $this->chore(['title' => "Chore {$n}"]);
        }

        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test('display.wall');
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
