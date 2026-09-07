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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Where a child's stars went. */
class LedgerViewTest extends TestCase
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
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true, 'pin' => '1234',
        ]);
        $this->simon = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Simon', 'is_child' => false,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function ledger(): PointsLedger
    {
        return app(PointsLedger::class);
    }

    protected function open(): Testable
    {
        return Livewire::test('kids.ledger')->call('show', $this->joey->id);
    }

    #[Test]
    public function the_running_balance_is_the_balance_after_each_line(): void
    {
        $this->ledger()->adjust($this->joey, 10, 'Birthday');
        $this->ledger()->adjust($this->joey, 5, 'Helping');
        $this->ledger()->adjust($this->joey, -3, 'Broke a window');

        $rows = $this->ledger()->history($this->joey);

        // Newest first, each showing what they had once it had happened.
        $this->assertSame(
            [['Broke a window', -3, 12], ['Helping', 5, 15], ['Birthday', 10, 10]],
            $rows->map(fn ($row) => [$row['entry']->reason, $row['entry']->points, $row['balance']])->all(),
        );
    }

    #[Test]
    public function the_oldest_line_shown_still_adds_up(): void
    {
        foreach (range(1, 10) as $n) {
            $this->ledger()->adjust($this->joey, $n, "Entry {$n}");
        }

        // Only the last three, but the running balance is still the real one.
        $rows = $this->ledger()->history($this->joey, 3);

        $this->assertCount(3, $rows);
        $this->assertSame(55, $rows->first()['balance']);
        $this->assertSame(55 - 10 - 9, $rows->last()['balance']);
    }

    #[Test]
    public function a_chore_ticked_and_untickedeaves_both_lines_visible(): void
    {
        $chore = Chore::factory()->create([
            'household_id' => $this->household->id,
            'member_id' => $this->joey->id,
            'title' => 'Feed the cat',
            'points' => 5,
        ]);

        $instance = app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-09'), $this->joey);
        app(ChoreBoard::class)->uncomplete($instance);

        $rows = $this->ledger()->history($this->joey);

        $this->assertSame([-5, 5], $rows->pluck('entry.points')->all());
        $this->assertSame([0, 5], $rows->pluck('balance')->all());
    }

    #[Test]
    public function spending_shows_up_as_its_own_kind_of_line(): void
    {
        $this->ledger()->adjust($this->joey, 50, 'Starting balance');
        $reward = Reward::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Screen time', 'cost' => 20,
        ]);

        $request = app(RewardShop::class)->request($this->joey, $reward);
        app(RewardShop::class)->grant($request, $this->simon);

        $this->open()
            ->assertSee('Screen time')
            ->assertSee('-20')
            ->assertSee('Starting balance');
    }

    #[Test]
    public function the_history_is_reached_from_the_running_total(): void
    {
        $this->ledger()->adjust($this->joey, 10, 'Birthday');

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->assertSeeHtml('show-ledger');
    }

    #[Test]
    public function it_needs_no_pin_to_look_at(): void
    {
        $this->ledger()->adjust($this->joey, 10, 'Birthday');

        $this->open()
            ->assertSee('Birthday')
            ->assertNotDispatched('need-pin')
            ->assertNotDispatched('need-adult-pin');
    }

    #[Test]
    public function it_is_read_only(): void
    {
        // Nothing on it should be able to change a balance.
        $methods = collect((new \ReflectionClass(Livewire::test('kids.ledger')->instance()))
            ->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->filter(fn ($m) => $m->class === (new \ReflectionClass(Livewire::test('kids.ledger')->instance()))->getName())
            ->map(fn ($m) => $m->getName())
            ->values();

        $this->assertSame(
            ['show', 'close', 'showEarlier', 'member', 'rows', 'total', 'balance', 'refresh'],
            $methods->all(),
        );
    }

    #[Test]
    public function dates_are_headed_and_only_once_each(): void
    {
        // Reasons deliberately free of the words the headings use.
        CarbonImmutable::setTestNow('2026-09-08 09:00:00');
        $this->ledger()->adjust($this->joey, 3, 'Washing up');
        $this->ledger()->adjust($this->joey, 4, 'Bins out');

        CarbonImmutable::setTestNow('2026-09-09 09:00:00');
        $this->ledger()->adjust($this->joey, 5, 'Fed the cat');

        $html = $this->open()->html();

        $headings = preg_match_all('/uppercase">\s*(Today|Yesterday)\s*</', $html, $matches)
            ? $matches[1]
            : [];

        $this->assertSame(['Today', 'Yesterday'], $headings, 'One heading per day, not one per line.');
    }

    #[Test]
    public function earlier_entries_are_loaded_on_request(): void
    {
        foreach (range(1, 60) as $n) {
            $this->ledger()->adjust($this->joey, 1, "Entry {$n}");
        }

        $component = $this->open();

        $this->assertCount(50, $component->instance()->rows);
        $component->assertSee('Show earlier')->assertSee('60 entries');

        $component->call('showEarlier');

        $this->assertCount(60, $component->instance()->rows);
        $component->assertDontSee('Show earlier');
    }

    #[Test]
    public function a_child_with_no_history_is_told_so_rather_than_shown_nothing(): void
    {
        $this->open()->assertSee('No points yet.');
    }

    #[Test]
    public function money_is_shown_only_when_pocket_money_is_on(): void
    {
        $this->ledger()->adjust($this->joey, 100, 'Birthday');

        $this->open()->assertDontSee('£');

        $this->household->setAllowance(true, 5);
        Once::flush();

        $this->open()->assertSee('£5.00');
    }

    #[Test]
    public function one_childs_ledger_is_not_anothers(): void
    {
        $sienna = Member::factory()->create(['household_id' => $this->household->id, 'is_child' => true]);

        $this->ledger()->adjust($this->joey, 10, 'Joey earned this');
        $this->ledger()->adjust($sienna, 10, 'Sienna earned this');

        $this->open()
            ->assertSee('Joey earned this')
            ->assertDontSee('Sienna earned this');
    }

    #[Test]
    public function a_member_from_another_household_cannot_be_opened(): void
    {
        $stranger = Member::factory()->create(['household_id' => Household::factory()->create()->id]);

        Livewire::test('kids.ledger')
            ->call('show', $stranger->id)
            ->assertDontSee('points history')
            ->assertSet('memberId', $stranger->id);

        $this->assertNull(Livewire::test('kids.ledger')->call('show', $stranger->id)->instance()->member);
    }

    #[Test]
    public function reading_the_history_does_not_grow_with_its_length(): void
    {
        foreach (range(1, 5) as $n) {
            $this->ledger()->adjust($this->joey, 1, "Entry {$n}");
        }

        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->open();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $measure();
        $withFive = $measure();

        foreach (range(6, 40) as $n) {
            $this->ledger()->adjust($this->joey, 1, "Entry {$n}");
        }

        $this->assertSame($withFive, $measure(), 'The running balance must not cost a query per line.');
    }
}
