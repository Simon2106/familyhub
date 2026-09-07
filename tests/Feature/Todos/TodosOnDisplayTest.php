<?php

namespace Tests\Feature\Todos;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TodosOnDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $simon;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday.
        CarbonImmutable::setTestNow('2026-07-08 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create(['calendar_account_id' => $account->id, 'member_id' => null]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function todo(string $title, ?string $due = null, ?Member $member = null): ChecklistItem
    {
        return ChecklistItem::create([
            'checklist_id' => Checklist::home($this->household)->id,
            'title' => $title,
            'due_on' => $due,
            'member_id' => $member?->id,
        ]);
    }

    #[Test]
    public function a_dated_todo_lands_in_its_members_column_on_that_day(): void
    {
        $this->todo('Book MOT', '2026-07-09', $this->simon);

        $byDate = Livewire::test('display.wall')->instance()->todosByDate();

        $this->assertTrue($byDate->has('2026-07-09'));
        $this->assertSame(['Book MOT'], $byDate['2026-07-09'][$this->simon->id]->pluck('title')->all());
    }

    #[Test]
    public function a_dated_todo_with_no_member_lands_in_the_household_column(): void
    {
        $this->todo('Bins out', '2026-07-09');

        $byDate = Livewire::test('display.wall')->instance()->todosByDate();

        $this->assertSame(['Bins out'], $byDate['2026-07-09']['household']->pluck('title')->all());
    }

    #[Test]
    public function an_undated_todo_stays_out_of_the_day_columns(): void
    {
        // It belongs in the To do panel, not pinned to an arbitrary day.
        $this->todo('Water the plants', null, $this->simon);

        $this->assertTrue(Livewire::test('display.wall')->instance()->todosByDate()->isEmpty());
    }

    #[Test]
    public function a_ticked_todo_leaves_the_day_columns(): void
    {
        $todo = $this->todo('Book MOT', '2026-07-09', $this->simon);
        $todo->toggle();

        $this->assertTrue(Livewire::test('display.wall')->instance()->todosByDate()->isEmpty());
    }

    #[Test]
    public function a_todo_gives_the_household_a_column_on_a_day_with_no_events(): void
    {
        $this->todo('Bins out', '2026-07-09');

        $html = Livewire::test('display.wall')->html();

        // The column has to appear or the to-do would have nowhere to show.
        $this->assertStringContainsString('Household', $html);
    }

    #[Test]
    public function todos_are_marked_as_tasks_so_they_read_differently_from_events(): void
    {
        Event::factory()->create([
            'calendar_id' => $this->calendar->id,
            'title' => 'Dentist',
            'start_at' => CarbonImmutable::parse('2026-07-09 09:00', 'Europe/London'),
            'end_at' => CarbonImmutable::parse('2026-07-09 10:00', 'Europe/London'),
        ]);
        $this->todo('Book MOT', '2026-07-09', $this->simon);

        $html = Livewire::test('display.wall')->html();

        $this->assertStringContainsString('Book MOT', $html);
        // Labelled, so a task is never mistaken for an appointment.
        $this->assertStringContainsString('To do', $html);
    }

    #[Test]
    public function the_todo_panel_is_on_the_display(): void
    {
        Livewire::test('display.wall')->assertSee('todos.panel', escape: false);
    }

    #[Test]
    public function the_day_columns_refresh_when_a_todo_changes(): void
    {
        $this->todo('Book MOT', '2026-07-09', $this->simon);

        $component = Livewire::test('display.wall');
        $this->assertTrue($component->instance()->todosByDate()->has('2026-07-09'));

        ChecklistItem::firstOrFail()->toggle();

        $component->dispatch('todos-changed');

        $this->assertTrue($component->instance()->todosByDate()->isEmpty());
    }

    #[Test]
    public function a_todo_beyond_the_horizon_is_not_loaded_for_the_columns(): void
    {
        $this->todo('Renew passport', '2027-01-01', $this->simon);

        $this->assertTrue(Livewire::test('display.wall')->instance()->todosByDate()->isEmpty());
    }

    #[Test]
    public function ticked_todos_are_findable_under_done_on_the_lists_tab(): void
    {
        $todo = $this->todo('Book MOT');
        $todo->toggle();

        Livewire::test('display.lists')
            ->assertSee('Done (1)')
            ->assertSee('Book MOT');
    }

    #[Test]
    public function ticking_on_the_home_panel_is_reflected_on_the_lists_tab(): void
    {
        $todo = $this->todo('Water the plants');

        // An already-rendered Lists tab, as on the wall or below the phone's
        // To do panel.
        $lists = Livewire::test('display.lists')
            ->assertSee('Water the plants')
            ->assertDontSee('Done (1)');

        // Tick it on the home To do panel.
        Livewire::test('todos.panel')->call('toggle', $todo->id);

        // The Lists tab has to follow. It used to refresh only when a parent
        // component happened to re-render it, which was true on the wall and
        // false on a phone.
        $lists->dispatch('todos-changed')->assertSee('Done (1)');

        $this->assertTrue($todo->fresh()->is_done);
    }

    #[Test]
    public function the_home_panel_announces_a_tick_so_other_views_can_follow(): void
    {
        $todo = $this->todo('Water the plants');

        Livewire::test('todos.panel')
            ->call('toggle', $todo->id)
            ->assertDispatched('todos-changed');
    }

    #[Test]
    public function ticking_on_the_lists_tab_is_reflected_on_the_home_panel(): void
    {
        $todo = $this->todo('Water the plants');

        $panel = Livewire::test('todos.panel')->assertSee('Water the plants');

        Livewire::test('display.lists')
            ->call('toggle', $todo->id)
            ->assertDispatched('todos-changed');

        // The panel keeps just-ticked items briefly so they can fade, so the
        // check is on the record rather than the markup.
        $panel->dispatch('todos-changed');

        $this->assertTrue($todo->fresh()->is_done);
        $this->assertTrue(
            $panel->instance()->todos()->firstWhere('id', $todo->id)?->is_done,
            'The home panel must show the item as ticked after it was ticked on the Lists tab.',
        );
    }

    #[Test]
    public function clearing_done_on_the_lists_tab_announces_the_change(): void
    {
        $todo = $this->todo('Water the plants');
        $todo->toggle();

        Livewire::test('display.lists')
            ->call('clearDone', Checklist::home($this->household)->id)
            ->assertDispatched('todos-changed');
    }

    #[Test]
    public function the_lists_tab_separates_open_from_done(): void
    {
        $this->todo('Still to do');
        $this->todo('Finished')->toggle();

        $html = Livewire::test('display.lists')->html();

        $this->assertStringContainsString('Still to do', $html);
        $this->assertStringContainsString('Done (1)', $html);
    }
}
