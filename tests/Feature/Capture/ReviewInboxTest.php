<?php

namespace Tests\Feature\Capture;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Capture;
use App\Models\CaptureItem;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class ReviewInboxTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Calendar $calendar;

    protected Member $sienna;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-07 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $this->calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id,
            'external_id' => FakeICloud::CALENDAR,
            'name' => 'Family',
        ]);

        $this->sienna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Sienna']);

        FakeICloud::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        FakeICloud::reset();

        parent::tearDown();
    }

    protected function item(array $attributes = []): CaptureItem
    {
        $capture = Capture::factory()->reviewing()->create(['household_id' => $this->household->id]);

        return CaptureItem::factory()->create(array_merge(['capture_id' => $capture->id], $attributes));
    }

    #[Test]
    public function the_page_requires_a_signed_in_parent(): void
    {
        auth()->logout();

        $this->get('/app/review')->assertRedirect('/login');
    }

    #[Test]
    public function pending_items_are_listed_for_review(): void
    {
        $this->item(['title' => 'Parents evening']);

        Livewire::test('capture.review')->assertSee('Parents evening');
    }

    #[Test]
    public function nothing_reaches_a_calendar_before_it_is_accepted(): void
    {
        $this->item(['title' => 'Parents evening']);

        // The whole point of the review queue.
        $this->assertSame(0, Event::count());
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    #[Test]
    public function accepting_an_event_writes_it_to_icloud(): void
    {
        $item = $this->item([
            'title' => 'Parents evening',
            'start_at' => CarbonImmutable::parse('2026-09-15 18:00', 'Europe/London'),
            'end_at' => CarbonImmutable::parse('2026-09-15 20:00', 'Europe/London'),
            'calendar_id' => $this->calendar->id,
        ]);

        Livewire::test('capture.review')->call('accept', $item->id);

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains((string) $r->body(), 'SUMMARY:Parents evening'));

        $item->refresh();
        $this->assertSame('accepted', $item->status);
        $this->assertNotNull($item->event_id);
        $this->assertSame('Parents evening', Event::firstOrFail()->title);
    }

    #[Test]
    public function accepting_pins_the_member_the_reviewer_chose(): void
    {
        $item = $this->item([
            'title' => 'Swimming gala',
            'calendar_id' => $this->calendar->id,
            'member_id' => $this->sienna->id,
        ]);

        Livewire::test('capture.review')->call('accept', $item->id);

        $event = Event::firstOrFail();

        // A reviewer naming a member has said something the title may not.
        $this->assertSame('manual', $event->attribution);
        $this->assertSame([$this->sienna->id], $event->members->pluck('id')->all());
    }

    #[Test]
    public function accepting_a_task_creates_a_to_do_rather_than_an_event(): void
    {
        $item = $this->item([
            'type' => 'task',
            'title' => 'Return the trip form',
            'start_at' => CarbonImmutable::parse('2026-09-12 00:00', 'Europe/London'),
        ]);

        Livewire::test('capture.review')->call('accept', $item->id);

        $this->assertSame(0, Event::count());

        $todo = ChecklistItem::firstOrFail();
        $this->assertSame('Return the trip form', $todo->title);
        $this->assertSame('2026-09-12', $todo->due_on->toDateString());
    }

    #[Test]
    public function an_undated_event_becomes_a_to_do_rather_than_being_refused(): void
    {
        // Inventing a date would be worse, and refusing it loses the item.
        $item = $this->item(['title' => 'Book the school photo', 'start_at' => null, 'end_at' => null]);

        Livewire::test('capture.review')->call('accept', $item->id);

        $this->assertSame(0, Event::count());
        $this->assertSame('Book the school photo', ChecklistItem::firstOrFail()->title);
    }

    #[Test]
    public function rejecting_an_item_creates_nothing(): void
    {
        $item = $this->item();

        Livewire::test('capture.review')->call('reject', $item->id);

        $this->assertSame('rejected', $item->fresh()->status);
        $this->assertSame(0, Event::count());
        $this->assertSame(0, ChecklistItem::count());
    }

    #[Test]
    public function accept_all_confident_leaves_the_unsure_ones_behind(): void
    {
        $capture = Capture::factory()->reviewing()->create(['household_id' => $this->household->id]);

        $sure = CaptureItem::factory()->create(['capture_id' => $capture->id, 'confidence' => 95, 'calendar_id' => $this->calendar->id]);
        $unsure = CaptureItem::factory()->unsure()->create(['capture_id' => $capture->id, 'calendar_id' => $this->calendar->id]);

        Livewire::test('capture.review')->call('acceptConfident', $capture->id);

        $this->assertSame('accepted', $sure->fresh()->status);
        $this->assertSame('pending', $unsure->fresh()->status, 'An unsure item still needs a person.');
    }

    #[Test]
    public function a_capture_closes_once_every_item_is_settled(): void
    {
        $capture = Capture::factory()->reviewing()->create(['household_id' => $this->household->id]);
        $a = CaptureItem::factory()->create(['capture_id' => $capture->id, 'calendar_id' => $this->calendar->id]);
        $b = CaptureItem::factory()->create(['capture_id' => $capture->id]);

        $component = Livewire::test('capture.review');
        $component->call('accept', $a->id);
        $this->assertSame('reviewing', $capture->fresh()->status);

        $component->call('reject', $b->id);
        $this->assertSame('done', $capture->fresh()->status);
    }

    #[Test]
    public function accepting_without_a_calendar_says_so_rather_than_failing(): void
    {
        Calendar::query()->delete();

        $item = $this->item(['title' => 'Parents evening']);

        $component = Livewire::test('capture.review')->call('accept', $item->id);

        $this->assertStringContainsString('Connect an iCloud calendar', $component->get('error'));
        $this->assertSame('pending', $item->fresh()->status, 'The item stays reviewable.');
    }

    #[Test]
    public function an_item_can_be_corrected_before_it_is_accepted(): void
    {
        $item = $this->item(['title' => 'Parents eveing', 'confidence' => 40]);

        Livewire::test('capture.review', ['editable' => true])
            ->call('edit', $item->id)
            ->set('title', 'Parents evening')
            ->set('date', '2026-09-15')
            ->set('time', '18:00')
            ->set('memberId', (string) $this->sienna->id)
            ->set('calendarId', (string) $this->calendar->id)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $item->refresh();
        $this->assertSame('Parents evening', $item->title);
        $this->assertSame('18:00', $item->start_at->timezone('Europe/London')->format('H:i'));
        $this->assertSame($this->sienna->id, $item->member_id);
    }

    #[Test]
    public function the_wall_cannot_edit_an_item(): void
    {
        $item = $this->item();

        // The wall accepts or rejects; correcting a title belongs on a phone.
        Livewire::test('capture.review')->call('edit', $item->id)->assertSet('editingId', null);
    }

    #[Test]
    public function dismissing_a_capture_rejects_everything_left(): void
    {
        $capture = Capture::factory()->reviewing()->create(['household_id' => $this->household->id]);
        CaptureItem::factory()->count(3)->create(['capture_id' => $capture->id]);

        Livewire::test('capture.review')->call('dismissCapture', $capture->id);

        $this->assertSame('done', $capture->fresh()->status);
        $this->assertSame(3, CaptureItem::where('status', 'rejected')->count());
        $this->assertSame(0, Event::count());
    }

    #[Test]
    public function a_failed_capture_can_be_retried(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $capture = Capture::factory()->failed()->create(['household_id' => $this->household->id]);

        Livewire::test('capture.review')->call('retry', $capture->id);

        $this->assertSame('pending', $capture->fresh()->status);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessCaptureJob::class);
    }

    #[Test]
    public function another_households_item_is_out_of_reach(): void
    {
        $other = Capture::factory()->reviewing()->create(['household_id' => Household::factory()->create()->id]);
        $item = CaptureItem::factory()->create(['capture_id' => $other->id]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('capture.review')->call('accept', $item->id);
    }
}
