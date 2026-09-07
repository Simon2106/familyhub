<?php

namespace Tests\Feature\Todos;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TodoPanelTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $simon;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-08 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function todo(string $title, ?string $due = null, ?Member $member = null, bool $done = false): ChecklistItem
    {
        return ChecklistItem::create([
            'checklist_id' => Checklist::home($this->household)->id,
            'title' => $title,
            'due_on' => $due,
            'member_id' => $member?->id,
            'is_done' => $done,
            'done_at' => $done ? now() : null,
        ]);
    }

    #[Test]
    public function the_home_list_is_a_checklist_not_a_separate_model(): void
    {
        $list = Checklist::home($this->household);

        $this->assertInstanceOf(Checklist::class, $list);
        $this->assertTrue($list->is_home_list);
        $this->assertSame($this->household->id, $list->household_id);
    }

    #[Test]
    public function the_home_list_is_created_once_and_reused(): void
    {
        $first = Checklist::home($this->household);
        $second = Checklist::home($this->household);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Checklist::where('is_home_list', true)->count());
    }

    #[Test]
    public function it_sorts_overdue_then_soonest_then_undated(): void
    {
        $this->todo('No date');
        $this->todo('Next week', '2026-07-15');
        $this->todo('Overdue', '2026-07-06');
        $this->todo('Today', '2026-07-08');

        $titles = Livewire::test('todos.panel')->instance()->todos()->pluck('title')->all();

        $this->assertSame(['Overdue', 'Today', 'Next week', 'No date'], $titles);
    }

    #[Test]
    public function ticking_a_todo_persists_it(): void
    {
        $todo = $this->todo('Book MOT');

        Livewire::test('todos.panel')->call('toggle', $todo->id);

        $todo->refresh();
        $this->assertTrue($todo->is_done);
        $this->assertNotNull($todo->done_at);
    }

    #[Test]
    public function a_just_ticked_todo_lingers_so_it_can_fade(): void
    {
        $todo = $this->todo('Book MOT');

        $component = Livewire::test('todos.panel');
        $component->call('toggle', $todo->id);

        // Still in the list right after ticking, or it would vanish mid-tap.
        $this->assertTrue($component->instance()->todos()->contains('id', $todo->id));
    }

    #[Test]
    public function a_ticked_todo_drops_off_once_the_linger_has_passed(): void
    {
        $todo = $this->todo('Book MOT');

        Livewire::test('todos.panel')->call('toggle', $todo->id);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());

        $this->assertFalse(Livewire::test('todos.panel')->instance()->todos()->contains('id', $todo->id));
    }

    #[Test]
    public function unticking_puts_it_back(): void
    {
        $todo = $this->todo('Book MOT', done: true);

        Livewire::test('todos.panel')->call('toggle', $todo->id);

        $todo->refresh();
        $this->assertFalse($todo->is_done);
        $this->assertNull($todo->done_at);
    }

    #[Test]
    public function a_todo_can_be_added_with_a_due_date_and_a_member(): void
    {
        Livewire::test('todos.panel')
            ->call('startAdding')
            ->set('title', 'Sign the school form')
            ->set('dueOn', '2026-07-10')
            ->set('memberId', (string) $this->simon->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('adding', false);

        $todo = ChecklistItem::firstOrFail();

        $this->assertSame('Sign the school form', $todo->title);
        $this->assertSame('2026-07-10', $todo->due_on->toDateString());
        $this->assertSame($this->simon->id, $todo->member_id);
        $this->assertSame(Checklist::home($this->household)->id, $todo->checklist_id);
    }

    #[Test]
    public function the_quick_add_is_a_dialog_not_an_inline_form(): void
    {
        // Inline, it pushed the panel down over the wall's tab bar and squeezed
        // the list out of sight.
        $html = Livewire::test('todos.panel')->call('startAdding')->html();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        // .modal-viewport keeps it clear of the tab bar and follows the keyboard.
        $this->assertStringContainsString('modal-viewport', $html);
    }

    #[Test]
    public function the_dialog_is_not_rendered_until_it_is_opened(): void
    {
        $html = Livewire::test('todos.panel')->html();

        $this->assertStringNotContainsString('role="dialog"', $html);
    }

    #[Test]
    public function a_todo_needs_a_title(): void
    {
        Livewire::test('todos.panel')
            ->call('startAdding')
            ->set('title', '')
            ->call('save')
            ->assertHasErrors('title');

        $this->assertSame(0, ChecklistItem::count());
    }

    #[Test]
    public function date_and_member_are_optional(): void
    {
        Livewire::test('todos.panel')
            ->call('startAdding')
            ->set('title', 'Water the plants')
            ->call('save')
            ->assertHasNoErrors();

        $todo = ChecklistItem::firstOrFail();

        $this->assertNull($todo->due_on);
        $this->assertNull($todo->member_id);
    }

    #[Test]
    public function the_wall_panel_cannot_edit_or_delete(): void
    {
        $todo = $this->todo('Book MOT');

        // The wall ticks and adds; editing belongs on a phone.
        $component = Livewire::test('todos.panel');
        $component->call('edit', $todo->id)->assertSet('editingId', null);
        $component->call('deleteItem', $todo->id);

        $this->assertModelExists($todo);
    }

    #[Test]
    public function the_phone_panel_can_edit(): void
    {
        $todo = $this->todo('Book MOT', '2026-07-10', $this->simon);

        Livewire::test('todos.panel', ['editable' => true])
            ->call('edit', $todo->id)
            ->assertSet('title', 'Book MOT')
            ->assertSet('dueOn', '2026-07-10')
            ->assertSet('memberId', (string) $this->simon->id)
            ->set('title', 'Book the MOT')
            ->set('dueOn', '')
            ->call('save')
            ->assertHasNoErrors();

        $todo->refresh();
        $this->assertSame('Book the MOT', $todo->title);
        $this->assertNull($todo->due_on);
    }

    #[Test]
    public function the_phone_panel_can_delete(): void
    {
        $todo = $this->todo('Book MOT');

        Livewire::test('todos.panel', ['editable' => true])->call('deleteItem', $todo->id);

        $this->assertModelMissing($todo);
    }

    #[Test]
    public function another_households_todo_is_out_of_reach(): void
    {
        Checklist::home($this->household);

        $other = Household::factory()->create();
        $otherItem = ChecklistItem::create([
            'checklist_id' => Checklist::home($other)->id,
            'title' => 'Not yours',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('todos.panel')->call('toggle', $otherItem->id);
    }

    #[Test]
    public function overdue_is_relative_to_the_households_today(): void
    {
        $overdue = $this->todo('Chase the plumber', '2026-07-07');
        $today = $this->todo('Book MOT', '2026-07-08');
        $later = $this->todo('Library books', '2026-07-09');

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($today->isOverdue());
        $this->assertFalse($later->isOverdue());
        $this->assertTrue($today->isDueToday());
    }

    #[Test]
    public function a_done_todo_is_never_overdue(): void
    {
        $todo = $this->todo('Chase the plumber', '2026-07-01', done: true);

        $this->assertFalse($todo->isOverdue());
    }
}
