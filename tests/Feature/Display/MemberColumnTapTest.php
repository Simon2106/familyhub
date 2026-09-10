<?php

namespace Tests\Feature\Display;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A member's column is the tap target, not the dot beside their name.
 *
 * Reported from the wall: tapping a member's dot opened their view, and
 * tapping anywhere else in their column did nothing — which on a screen nobody
 * stands close to reads as a broken display rather than as a small target.
 */
class MemberColumnTapTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
        Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);
    }

    protected function wall(): string
    {
        return Livewire::test('display.wall')->html();
    }

    #[Test]
    public function every_cell_in_the_week_grid_opens_its_day(): void
    {
        // Only the date at the end of the row used to, so six of the seven
        // things a finger might land on did nothing.
        $html = $this->wall();

        preg_match_all('/<button[^>]*wire:key="wk-cell-[^"]*"/', $html, $cells);

        $this->assertNotEmpty($cells[0], 'The member cells are not buttons.');
        $this->assertStringContainsString('aria-label="Simon, ', $html);
    }

    #[Test]
    public function so_does_the_household_column(): void
    {
        // The household column only exists when something is in it.
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id,
            'member_id' => null,
        ]);
        Event::factory()->create([
            'calendar_id' => $calendar->id,
            'title' => 'Bin day',
            'start_at' => $this->household->todayLocal()->addHours(8),
            'end_at' => $this->household->todayLocal()->addHours(9),
        ]);

        $this->assertStringContainsString('aria-label="Household, ', $this->wall());
    }

    #[Test]
    public function a_childs_heading_opens_their_day_and_an_adults_opens_today(): void
    {
        $html = $this->wall();

        // The dot stays the cue; the heading around it is the target.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*wire:key="wk-head-\d+"/',
            $html,
        );
        $this->assertStringContainsString('aria-label="Joey\'s day"', $html);
        $this->assertStringContainsString('aria-label="Simon"', $html);
    }

    #[Test]
    public function a_childs_whole_day_column_is_the_door_into_their_day(): void
    {
        $html = $this->wall();

        $this->assertStringContainsString('role="button"', $html);
        $this->assertStringContainsString('show-my-day', $html);
    }

    #[Test]
    public function but_anything_inside_it_still_gets_the_tap_first(): void
    {
        // A chore tile, a link or a checkbox in the column must not be
        // swallowed by the column behind it.
        $this->assertStringContainsString(
            "closest('button, a, input, label')",
            $this->wall(),
        );
    }

    #[Test]
    public function an_adults_column_does_not_open_an_empty_childs_panel(): void
    {
        // my-day is chores, routines and points; for an adult it would be a
        // blank dialog, which is worse than a tap that does nothing.
        $html = $this->wall();

        $simon = Member::firstWhere('name', 'Simon');

        $this->assertStringNotContainsString(
            "show-my-day', { member: {$simon->id}, date:",
            $html,
        );
    }
}
