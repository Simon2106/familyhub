<?php

namespace Tests\Feature\CalDav;

use App\Jobs\SyncCalendarAccountJob;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class AdminCalendarsTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function tearDown(): void
    {
        FakeICloud::reset();

        parent::tearDown();
    }

    #[Test]
    public function the_page_requires_a_signed_in_parent(): void
    {
        auth()->logout();

        $this->get('/admin/calendars')->assertRedirect('/login');
    }

    #[Test]
    public function an_account_can_be_connected_from_the_admin_page(): void
    {
        FakeICloud::fake();
        Queue::fake();

        Livewire::test('admin.calendars')
            ->set('label', "Simon's iCloud")
            ->set('appleId', 'simon@example.com')
            ->set('appPassword', 'abcd-efgh-ijkl-mnop')
            ->call('connect')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('calendar_accounts', [
            'provider' => 'icloud',
            'external_account_id' => 'simon@example.com',
            'label' => "Simon's iCloud",
        ]);

        Queue::assertPushed(SyncCalendarAccountJob::class);
    }

    #[Test]
    public function bad_credentials_are_shown_without_storing_anything(): void
    {
        Http::fake(['caldav.icloud.com/*' => Http::response('', 401)]);

        $component = Livewire::test('admin.calendars')
            ->set('label', 'Simon')
            ->set('appleId', 'simon@example.com')
            ->set('appPassword', 'not-an-app-password')
            ->call('connect');

        $this->assertStringContainsString('app-specific password', $component->get('connectError'));
        $this->assertSame(0, CalendarAccount::count());
    }

    #[Test]
    public function the_form_validates_before_calling_icloud(): void
    {
        Http::fake();

        Livewire::test('admin.calendars')
            ->set('label', '')
            ->set('appleId', 'not-an-email')
            ->set('appPassword', 'short')
            ->call('connect')
            ->assertHasErrors(['label', 'appleId', 'appPassword']);

        Http::assertNothingSent();
    }

    #[Test]
    public function several_accounts_are_listed_together(): void
    {
        CalendarAccount::factory()->create(['household_id' => $this->household->id, 'label' => 'Simon']);
        CalendarAccount::factory()->create(['household_id' => $this->household->id, 'label' => 'Partner']);

        Livewire::test('admin.calendars')
            ->assertSee('Simon')
            ->assertSee('Partner');
    }

    #[Test]
    public function a_sync_error_is_surfaced_on_the_page(): void
    {
        CalendarAccount::factory()->broken('iCloud rejected those credentials.')->create([
            'household_id' => $this->household->id,
        ]);

        Livewire::test('admin.calendars')
            ->assertSee('Error')
            ->assertSee('iCloud rejected those credentials.');
    }

    #[Test]
    public function sync_now_queues_a_job(): void
    {
        Queue::fake();

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('admin.calendars')->call('syncNow', $account->id);

        Queue::assertPushed(fn (SyncCalendarAccountJob $job) => $job->account->is($account) && ! $job->force);
    }

    #[Test]
    public function force_resync_queues_a_forced_job(): void
    {
        Queue::fake();

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('admin.calendars')->call('forceResync', $account->id);

        Queue::assertPushed(fn (SyncCalendarAccountJob $job) => $job->force === true);
    }

    #[Test]
    public function a_calendar_can_be_assigned_to_a_member(): void
    {
        $member = Member::factory()->create(['household_id' => $this->household->id]);
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id]);

        Livewire::test('admin.calendars')->call('assignMember', $calendar->id, (string) $member->id);

        $this->assertSame($member->id, $calendar->fresh()->member_id);
    }

    #[Test]
    public function a_calendar_can_be_unassigned(): void
    {
        $member = Member::factory()->create(['household_id' => $this->household->id]);
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id, 'member_id' => $member->id]);

        Livewire::test('admin.calendars')->call('assignMember', $calendar->id, '');

        $this->assertNull($calendar->fresh()->member_id);
    }

    #[Test]
    public function a_calendar_can_be_hidden_from_the_wall(): void
    {
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id, 'is_visible' => true]);

        Livewire::test('admin.calendars')->call('toggleVisible', $calendar->id);

        $this->assertFalse($calendar->fresh()->is_visible);
    }

    #[Test]
    public function hiding_a_calendar_can_be_undone(): void
    {
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id, 'is_visible' => true]);

        $component = Livewire::test('admin.calendars');
        $component->call('toggleVisible', $calendar->id);
        $component->call('toggleVisible', $calendar->id);

        $this->assertTrue($calendar->fresh()->is_visible);
    }

    #[Test]
    public function visibility_is_a_labelled_switch(): void
    {
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        Calendar::factory()->create(['calendar_account_id' => $account->id, 'is_visible' => true]);

        Livewire::test('admin.calendars')
            ->assertSee('Show on display')
            // role=switch so assistive tech announces it as a toggle, not a button.
            ->assertSee('role="switch"', escape: false)
            ->assertSee('aria-checked', escape: false);
    }

    #[Test]
    public function disconnecting_removes_the_account_and_its_events(): void
    {
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id]);
        $event = Event::factory()->create(['calendar_id' => $calendar->id]);

        Livewire::test('admin.calendars')->call('disconnect', $account->id);

        $this->assertModelMissing($account);
        $this->assertModelMissing($calendar);
        $this->assertModelMissing($event);
    }

    #[Test]
    public function another_households_account_cannot_be_touched(): void
    {
        $other = CalendarAccount::factory()->create([
            'household_id' => Household::factory()->create()->id,
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('admin.calendars')->call('disconnect', $other->id);
    }

    #[Test]
    public function seeded_demo_data_is_not_offered_a_sync_button(): void
    {
        // It has no credentials, so syncing it could only fail confusingly.
        $demo = CalendarAccount::factory()->create([
            'household_id' => $this->household->id,
            'provider' => 'demo',
            'label' => 'Demo data',
        ]);
        Calendar::factory()->create(['calendar_account_id' => $demo->id]);

        Livewire::test('admin.calendars')
            ->assertSee('Demo data')
            ->assertSee('Never synced.')
            ->assertDontSee('Sync now');
    }

    #[Test]
    public function demo_data_can_be_cleared_out(): void
    {
        $demo = CalendarAccount::factory()->create([
            'household_id' => $this->household->id,
            'provider' => 'demo',
        ]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $demo->id]);

        Livewire::test('admin.calendars')->call('removeDemoData');

        $this->assertModelMissing($demo);
        $this->assertModelMissing($calendar);
    }

    #[Test]
    public function the_app_specific_password_is_never_rendered_back(): void
    {
        FakeICloud::fake();
        Queue::fake();

        $component = Livewire::test('admin.calendars')
            ->set('label', 'Simon')
            ->set('appleId', 'simon@example.com')
            ->set('appPassword', 'abcd-efgh-ijkl-mnop')
            ->call('connect');

        // Cleared from the component, and never echoed into the account list.
        $this->assertSame('', $component->get('appPassword'));
        $component->assertDontSee('abcd-efgh-ijkl-mnop');
    }
}
