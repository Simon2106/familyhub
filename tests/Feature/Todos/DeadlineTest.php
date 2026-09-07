<?php

namespace Tests\Feature\Todos;

use App\Jobs\SyncTodoMirrorJob;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Household;
use App\Services\Todos\DeadlineMirror;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

/**
 * Deadline-aware to-dos: when one starts being shown, how hard it shouts, and
 * the optional iCloud reminder that carries it to everyone's phone.
 */
class DeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-07 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id,
            'external_id' => FakeICloud::CALENDAR,
            'name' => 'Family',
            'is_writable' => true,
        ]);

        FakeICloud::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        FakeICloud::reset();

        parent::tearDown();
    }

    protected function todo(array $attributes = []): ChecklistItem
    {
        return ChecklistItem::create($attributes + [
            'checklist_id' => Checklist::home($this->household)->id,
            'title' => 'Return the consent form',
        ]);
    }

    #[Test]
    public function the_badge_says_how_long_is_left(): void
    {
        $cases = [
            '2026-09-05' => 'Overdue by 2 days',
            '2026-09-06' => 'Overdue by a day',
            '2026-09-07' => 'Due today',
            '2026-09-08' => 'Due tomorrow',
            '2026-09-12' => 'Due in 5 days',
        ];

        foreach ($cases as $due => $expected) {
            $this->assertSame($expected, $this->todo(['due_on' => $due])->dueBadge(), "for {$due}");
        }

        $this->assertNull($this->todo()->dueBadge(), 'An undated to-do has no deadline to report.');
    }

    #[Test]
    public function urgency_climbs_as_the_deadline_nears(): void
    {
        $cases = [
            '2026-09-01' => 'overdue',
            '2026-09-07' => 'today',
            '2026-09-08' => 'tomorrow',
            '2026-09-10' => 'soon',
            '2026-09-20' => 'later',
        ];

        foreach ($cases as $due => $expected) {
            $this->assertSame($expected, $this->todo(['due_on' => $due])->urgency(), "for {$due}");
        }

        $this->assertSame('none', $this->todo()->urgency());
    }

    #[Test]
    public function a_deadline_stays_out_of_the_way_until_its_lead_time(): void
    {
        // Default lead is seven days, so the boundary is 14 September.
        $this->assertTrue($this->todo(['due_on' => '2026-09-14'])->isSurfaced());
        $this->assertFalse($this->todo(['due_on' => '2026-09-15'])->isSurfaced());

        // And an undated to-do has nothing to wait for.
        $this->assertTrue($this->todo()->isSurfaced());
    }

    #[Test]
    public function the_household_can_change_how_early_things_appear(): void
    {
        $far = $this->todo(['due_on' => '2026-10-01']);

        $this->assertFalse($far->isSurfaced());

        $this->household->setTodoLeadDays(30);

        $this->assertTrue($far->fresh()->isSurfaced());
    }

    #[Test]
    public function a_single_task_can_override_the_lead_time(): void
    {
        $item = $this->todo(['due_on' => '2026-12-01', 'surface_from' => '2026-09-06']);

        $this->assertTrue($item->isSurfaced(), 'An explicit surface date beats the household default.');
        $this->assertSame('2026-09-06', $item->surfaceFrom()->toDateString());

        // The override cuts both ways: it can hold something back as well.
        $held = $this->todo(['due_on' => '2026-09-08', 'surface_from' => '2026-09-08']);

        $this->assertFalse($held->isSurfaced());
    }

    #[Test]
    public function the_home_panel_shows_only_what_has_surfaced(): void
    {
        $this->todo(['title' => 'Sign the form', 'due_on' => '2026-09-09']);
        $this->todo(['title' => 'School photos money', 'due_on' => '2026-11-01']);

        Livewire::test('todos.panel')
            ->assertSee('Sign the form')
            ->assertSee('Due in 2 days')
            ->assertDontSee('School photos money')
            ->assertSee('1 more due later');
    }

    #[Test]
    public function the_lists_tab_keeps_the_rest_under_upcoming(): void
    {
        $this->todo(['title' => 'Sign the form', 'due_on' => '2026-09-09']);
        $this->todo(['title' => 'School photos money', 'due_on' => '2026-11-01']);

        Livewire::test('display.lists')
            ->assertSee('Sign the form')
            ->assertSee('Upcoming (1)')
            ->assertSee('School photos money');
    }

    #[Test]
    public function nothing_is_mirrored_until_the_household_asks_for_it(): void
    {
        $item = $this->todo(['due_on' => '2026-09-09']);

        $this->assertNull($item->fresh()->mirror_event_id);
        $this->assertSame(0, Event::count(), 'Writing to a shared calendar is opt-in.');
    }

    #[Test]
    public function a_dated_task_is_mirrored_onto_its_surface_date(): void
    {
        $this->household->setDeadlineMirror(true, $this->calendar->id);

        $item = $this->todo(['title' => 'Return the consent form', 'due_on' => '2026-09-24']);

        $event = $item->fresh()->mirrorEvent;

        $this->assertNotNull($event, 'A dated to-do should have gained a reminder.');
        $this->assertSame('Reminder: Return the consent form (due 24 Sep)', $event->title);
        $this->assertTrue($event->all_day);

        // Seven days before it is due — a reminder on the deadline is no use.
        $this->assertSame('2026-09-17', $event->start_at->timezone('Europe/London')->toDateString());
    }

    #[Test]
    public function an_undated_task_gets_no_reminder(): void
    {
        $this->household->setDeadlineMirror(true, $this->calendar->id);

        $this->todo(['title' => 'Buy more milk']);

        $this->assertSame(0, Event::count(), 'There is no date to remind anyone about.');
    }

    #[Test]
    public function changing_the_due_date_moves_the_reminder(): void
    {
        $this->household->setDeadlineMirror(true, $this->calendar->id);

        $item = $this->todo(['due_on' => '2026-09-24']);
        $eventId = $item->fresh()->mirror_event_id;

        $item->update(['due_on' => '2026-10-01']);

        $event = Event::find($eventId);

        $this->assertSame($eventId, $item->fresh()->mirror_event_id, 'The reminder should move, not multiply.');
        $this->assertSame(1, Event::count());
        $this->assertSame('2026-09-24', $event->start_at->timezone('Europe/London')->toDateString());
        $this->assertSame('Reminder: Return the consent form (due 1 Oct)', $event->title);
    }

    #[Test]
    public function a_surface_date_override_moves_the_reminder_too(): void
    {
        $this->household->setDeadlineMirror(true, $this->calendar->id);

        $item = $this->todo(['due_on' => '2026-09-24']);

        $item->update(['surface_from' => '2026-09-10']);

        $this->assertSame(
            '2026-09-10',
            Event::find($item->fresh()->mirror_event_id)->start_at->timezone('Europe/London')->toDateString(),
        );
    }

    #[Test]
    public function ticking_the_task_takes_the_reminder_off_everyones_calendar(): void
    {
        $this->household->setDeadlineMirror(true, $this->calendar->id);

        $item = $this->todo(['due_on' => '2026-09-24']);

        $this->assertSame(1, Event::count());

        $item->toggle();

        $this->assertSame(0, Event::count());
        $this->assertNull($item->fresh()->mirror_event_id);
    }

    #[Test]
    public function un_ticking_it_puts_the_reminder_back(): void
    {
        $this->household->setDeadlineMirror(true, $this->calendar->id);

        $item = $this->todo(['due_on' => '2026-09-24']);
        $item->toggle();
        $item->toggle();

        $this->assertSame(1, Event::count());
        $this->assertNotNull($item->fresh()->mirror_event_id);
    }

    #[Test]
    public function deleting_the_task_clears_up_after_itself(): void
    {
        $this->household->setDeadlineMirror(true, $this->calendar->id);

        $item = $this->todo(['due_on' => '2026-09-24']);

        $this->assertSame(1, Event::count());

        $item->delete();

        $this->assertSame(0, Event::count(), 'A deleted to-do must not leave a reminder behind.');
    }

    #[Test]
    public function turning_the_setting_off_withdraws_the_reminder(): void
    {
        $this->household->setDeadlineMirror(true, $this->calendar->id);

        $item = $this->todo(['due_on' => '2026-09-24']);

        $this->household->setDeadlineMirror(false);

        app(DeadlineMirror::class)->sync($item->fresh());

        $this->assertSame(0, Event::count());
    }

    #[Test]
    public function a_change_that_does_not_touch_the_reminder_does_not_queue_work(): void
    {
        Queue::fake([SyncTodoMirrorJob::class]);

        $item = $this->todo(['due_on' => '2026-09-24']);

        Queue::assertPushed(SyncTodoMirrorJob::class, 1);

        $item->update(['sort_order' => 5]);

        Queue::assertPushed(SyncTodoMirrorJob::class, 1);

        $item->update(['due_on' => '2026-09-25']);

        Queue::assertPushed(SyncTodoMirrorJob::class, 2);
    }
}
