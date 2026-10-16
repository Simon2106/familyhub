<?php

namespace Tests\Feature\Display;

use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a tap on the wall costs the server.
 *
 * Every Livewire action on the wall component re-renders the whole of it,
 * and the whole of it is three weeks of events, a fortnight of chores and
 * twenty-odd child components. This measures that, so a regression shows
 * up as a number rather than as "the kiosk feels slow".
 */
class WallRenderCostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What one render of the wall may cost.
     *
     * 63 as it stands, with headroom for a household bigger than the test's
     * four. It was 73 before the duplicated children were made lazy and the
     * screensaver stopped re-fetching events the grid already had. The
     * number is here to catch the next regression, not to be admired.
     */
    public const QUERY_BUDGET = 70;

    protected function setUp(): void
    {
        parent::setUp();

        config(['familyhub.display.token' => 'wall-token']);

        $household = Household::factory()->create();
        User::factory()->create(['household_id' => $household->id]);

        foreach (['Ada', 'Bo', 'Cy', 'Di'] as $i => $name) {
            Member::factory()->create([
                'household_id' => $household->id,
                'name' => $name,
                'is_child' => $i > 1,
            ]);
        }
    }

    /** @return array{queries: int, shapes: array<string, int>} */
    protected function cost(callable $render): array
    {
        $queries = [];

        DB::listen(function ($q) use (&$queries) {
            $queries[] = preg_replace('/\s+/', ' ', $q->sql);
        });

        $render();

        $shapes = array_count_values($queries);

        arsort($shapes);

        return ['queries' => count($queries), 'shapes' => $shapes];
    }

    #[Test]
    public function one_render_of_the_wall_stays_within_its_query_budget(): void
    {
        $cost = $this->cost(fn () => Livewire::test('display.wall'));

        $this->assertLessThanOrEqual(
            self::QUERY_BUDGET,
            $cost['queries'],
            "The wall is running {$cost['queries']} queries to draw once. "
            .'Worst repeat: '.key($cost['shapes']).' ×'.current($cost['shapes']),
        );
    }

    #[Test]
    public function nothing_on_the_wall_queries_once_per_member(): void
    {
        // The N+1 signature. With four members, a statement running four or
        // more times is a loop that should have been an eager load.
        $cost = $this->cost(fn () => Livewire::test('display.wall'));

        foreach ($cost['shapes'] as $sql => $count) {
            $this->assertLessThan(
                8,
                $count,
                "This ran {$count} times in one render, which is a loop: {$sql}",
            );
        }
    }

    #[Test]
    public function a_tap_on_a_card_does_not_redraw_the_whole_wall(): void
    {
        // Ticking a to-do should cost what the to-do panel costs, not what
        // three weeks of calendar costs.
        $wall = $this->cost(fn () => Livewire::test('display.wall'));
        $panel = $this->cost(fn () => Livewire::test('todos.panel'));

        $this->assertLessThan(
            $wall['queries'],
            $panel['queries'],
            'A card should be cheaper to redraw than the whole face.',
        );
    }
}
