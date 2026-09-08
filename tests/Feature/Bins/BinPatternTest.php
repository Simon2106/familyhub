<?php

namespace Tests\Feature\Bins;

use App\Models\BinCollection;
use App\Models\Household;
use App\Models\User;
use App\Services\Bins\BinPattern;
use App\Services\Bins\BinSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A fortnightly round written down, because the council publishes a PDF.
 */
class BinPatternTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 8 September 2026.
        CarbonImmutable::setTestNow('2026-09-08 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** The round at 5 The Ridgeway. */
    protected function theRidgeway(): array
    {
        return [
            'weekday' => 2,
            'anchor' => '2026-09-15',
            'weekly' => ['food'],
            'week_a' => ['recycling', 'paper', 'garden', 'electricals'],
            'week_b' => ['refuse'],
        ];
    }

    protected function usePattern(array $overrides = []): int
    {
        $this->household->setBinPattern($overrides + $this->theRidgeway());
        $this->household->setBinSource('pattern');

        return app(BinSchedule::class)->sync($this->household->fresh());
    }

    protected function kindsOn(string $date): array
    {
        return BinCollection::where('household_id', $this->household->id)
            ->where('on', $date)
            ->orderBy('kind')
            ->pluck('kind')
            ->all();
    }

    #[Test]
    public function week_a_is_the_anchor_week(): void
    {
        $pattern = BinPattern::fromArray($this->theRidgeway());

        $this->assertTrue($pattern->isWeekA(CarbonImmutable::parse('2026-09-15')));
        $this->assertFalse($pattern->isWeekA(CarbonImmutable::parse('2026-09-22')));
        $this->assertTrue($pattern->isWeekA(CarbonImmutable::parse('2026-09-29')));

        // And backwards, which is where a naive modulo gets it wrong.
        $this->assertFalse($pattern->isWeekA(CarbonImmutable::parse('2026-09-08')));
        $this->assertTrue($pattern->isWeekA(CarbonImmutable::parse('2026-09-01')));
    }

    #[Test]
    public function the_ridgeway_round_comes_out_as_described(): void
    {
        $this->usePattern();

        // Week A: everything but the general waste.
        $this->assertSame(
            ['electricals', 'food', 'garden', 'paper', 'recycling'],
            $this->kindsOn('2026-09-15'),
        );

        // Week B: general waste, and the food caddy as every week.
        $this->assertSame(['food', 'refuse'], $this->kindsOn('2026-09-22'));

        // And it keeps alternating.
        $this->assertSame(
            ['electricals', 'food', 'garden', 'paper', 'recycling'],
            $this->kindsOn('2026-09-29'),
        );
    }

    #[Test]
    public function it_only_ever_lands_on_the_collection_day(): void
    {
        $this->usePattern();

        $days = BinCollection::where('household_id', $this->household->id)
            ->pluck('on')
            ->map(fn ($on) => CarbonImmutable::parse($on)->dayOfWeekIso)
            ->unique()
            ->all();

        $this->assertSame([2], array_values($days), 'Tuesdays only.');
    }

    #[Test]
    public function it_generates_a_useful_way_ahead_but_not_for_ever(): void
    {
        $this->usePattern();

        $last = BinCollection::where('household_id', $this->household->id)->max('on');

        $this->assertGreaterThan('2026-11-01', $last);
        $this->assertLessThan('2027-01-01', $last);
    }

    #[Test]
    public function an_anchor_typed_a_day_out_is_nudged_rather_than_refused(): void
    {
        // A date on a phone is usually a day out, not meaningless.
        $pattern = BinPattern::fromArray(['weekday' => 2, 'anchor' => '2026-09-16'] + $this->theRidgeway());

        $this->assertSame('2026-09-15', $pattern->anchor->toDateString());
    }

    #[Test]
    public function a_pattern_with_no_bins_at_all_writes_nothing(): void
    {
        $count = $this->usePattern(['weekly' => [], 'week_a' => [], 'week_b' => []]);

        $this->assertSame(0, $count);
    }

    #[Test]
    public function an_unknown_bin_kind_is_dropped(): void
    {
        $this->usePattern(['weekly' => ['food', 'unicorns']]);

        $this->assertSame(['food', 'refuse'], $this->kindsOn('2026-09-22'));
    }

    #[Test]
    public function a_bank_holiday_moves_the_whole_day(): void
    {
        $this->usePattern();
        $this->household->setBinOverride('2026-09-15', '2026-09-17');

        app(BinSchedule::class)->sync($this->household->fresh());

        $this->assertSame([], $this->kindsOn('2026-09-15'));
        $this->assertSame(
            ['electricals', 'food', 'garden', 'paper', 'recycling'],
            $this->kindsOn('2026-09-17'),
            'The whole collection moves, not one bin.',
        );

        // The week after is untouched.
        $this->assertSame(['food', 'refuse'], $this->kindsOn('2026-09-22'));
    }

    #[Test]
    public function the_move_expires_once_it_is_in_the_past(): void
    {
        $this->household->setBinOverride('2026-09-15', '2026-09-17');
        $this->assertNotNull($this->household->fresh()->binOverride());

        // Left in place it would silently shift next Christmas as well.
        CarbonImmutable::setTestNow('2026-09-18 07:00:00');

        $this->assertNull($this->household->fresh()->binOverride());
    }

    #[Test]
    public function an_expired_move_stops_affecting_the_dates(): void
    {
        $this->usePattern();
        $this->household->setBinOverride('2026-09-15', '2026-09-17');
        app(BinSchedule::class)->sync($this->household->fresh());

        CarbonImmutable::setTestNow('2026-10-06 07:00:00');
        app(BinSchedule::class)->sync($this->household->fresh());

        $this->assertSame(
            ['electricals', 'food', 'garden', 'paper', 'recycling'],
            $this->kindsOn('2026-10-13'),
            'Back to the usual round.',
        );
    }

    #[Test]
    public function admin_writes_the_round_down_and_generates_from_it(): void
    {
        Livewire::test('admin.settings')
            ->set('binSource', 'pattern')
            ->set('binWeekday', 2)
            ->set('binAnchor', '2026-09-15')
            ->set('binWeekly', ['food'])
            ->set('binWeekA', ['recycling', 'paper', 'garden', 'electricals'])
            ->set('binWeekB', ['refuse'])
            ->call('saveBinPattern')
            ->assertHasNoErrors()
            ->assertDispatched('saved');

        $this->assertSame('pattern', $this->household->fresh()->binSource());
        $this->assertSame(['food', 'refuse'], $this->kindsOn('2026-09-22'));
    }

    #[Test]
    public function admin_can_move_the_next_collection_and_put_it_back(): void
    {
        $this->usePattern();

        // Wednesday, so "the next collection" is unambiguously the 15th —
        // today is itself a Tuesday and would otherwise be the one moved.
        CarbonImmutable::setTestNow('2026-09-09 07:00:00');

        Livewire::test('admin.settings')
            ->set('binMoveTo', '2026-09-17')
            ->call('moveNextCollection')
            ->assertHasNoErrors();

        $this->assertSame([], $this->kindsOn('2026-09-15'));

        Livewire::test('admin.settings')->call('clearBinOverride');

        $this->assertNotSame([], $this->kindsOn('2026-09-15'));
    }

    #[Test]
    public function the_wall_shows_the_pattern_the_same_as_a_feed(): void
    {
        $this->usePattern();

        // Next collection is Tuesday 15th; today is Tuesday 8th, so nothing
        // is close enough yet.
        CarbonImmutable::setTestNow('2026-09-14 07:00:00');

        Livewire::test('display.wall')->assertSee('Recycling');
    }

    #[Test]
    public function the_household_is_defaulted_to_the_ridgeway_round(): void
    {
        // The data migration ran as part of the test database build.
        $fresh = Household::factory()->create();

        $this->assertSame('none', $fresh->binSource(), 'A new household is not assumed to live here.');
    }

    #[Test]
    public function todays_collection_is_still_the_next_one(): void
    {
        // Today is a Tuesday. Whether the lorry has been is not something the
        // app can know, so it stays listed rather than being skipped.
        $this->usePattern();

        $next = Livewire::test('admin.settings')->instance()->nextCollection;

        $this->assertSame('2026-09-08', $next->on->toDateString());
    }
}
