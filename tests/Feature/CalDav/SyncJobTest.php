<?php

namespace Tests\Feature\CalDav;

use App\Jobs\SyncCalendarAccountJob;
use App\Jobs\SyncCalendarJob;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class SyncJobTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
    }

    protected function tearDown(): void
    {
        FakeICloud::reset();

        parent::tearDown();
    }

    #[Test]
    public function the_account_job_refreshes_calendars_then_fans_out(): void
    {
        FakeICloud::fake();
        // Partial fake: the account job must actually run, only its fan-out is captured.
        Queue::fake([SyncCalendarJob::class]);

        $account = CalendarAccount::factory()->create([
            'household_id' => $this->household->id,
            'calendar_home_url' => FakeICloud::HOME,
        ]);

        dispatch_sync(new SyncCalendarAccountJob($account));

        Queue::assertPushed(SyncCalendarJob::class, 1);
        $this->assertSame(1, $account->calendars()->count());
    }

    #[Test]
    public function it_does_not_sync_hidden_calendars(): void
    {
        FakeICloud::fake();
        Queue::fake([SyncCalendarJob::class]);

        $account = CalendarAccount::factory()->create([
            'household_id' => $this->household->id,
            'calendar_home_url' => FakeICloud::HOME,
        ]);

        // Pre-existing and hidden by the family, so it must be skipped.
        Calendar::factory()->hidden()->create([
            'calendar_account_id' => $account->id,
            'external_id' => '/12345678/calendars/hidden/',
        ]);

        dispatch_sync(new SyncCalendarAccountJob($account));

        Queue::assertPushed(SyncCalendarJob::class, 1);
    }

    #[Test]
    public function a_disabled_account_is_skipped_entirely(): void
    {
        FakeICloud::fake();
        Queue::fake([SyncCalendarJob::class]);

        $account = CalendarAccount::factory()->create([
            'household_id' => $this->household->id,
            'status' => 'disabled',
        ]);

        dispatch_sync(new SyncCalendarAccountJob($account));

        Queue::assertNotPushed(SyncCalendarJob::class);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_failure_is_recorded_against_the_account(): void
    {
        Http::fake(['caldav.icloud.com/*' => Http::response('', 401)]);

        $account = CalendarAccount::factory()->create([
            'household_id' => $this->household->id,
            'calendar_home_url' => FakeICloud::HOME,
        ]);

        $job = new SyncCalendarAccountJob($account);

        try {
            dispatch_sync($job);
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $account->refresh();
        $this->assertSame('error', $account->status);
        $this->assertStringContainsString('app-specific password', (string) $account->last_error);
    }

    #[Test]
    public function a_successful_sync_clears_a_previous_error(): void
    {
        FakeICloud::fake();
        Queue::fake([SyncCalendarJob::class]);

        $account = CalendarAccount::factory()->broken()->create([
            'household_id' => $this->household->id,
            'calendar_home_url' => FakeICloud::HOME,
        ]);

        dispatch_sync(new SyncCalendarAccountJob($account));

        $account->refresh();
        $this->assertSame('ok', $account->status);
        $this->assertNull($account->last_error);
        $this->assertNotNull($account->last_synced_at);
    }

    #[Test]
    public function the_calendar_job_pulls_events(): void
    {
        FakeICloud::fake(['calendar-query' => FakeICloud::multiStatus([
            '/12345678/calendars/home/swim.ics' => FakeICloud::event('swim-1', 'Swimming', '20260707T163000', '20260707T171500'),
        ])]);

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create([
            'calendar_account_id' => $account->id,
            'external_id' => FakeICloud::CALENDAR,
        ]);

        dispatch_sync(new SyncCalendarJob($calendar));

        $this->assertSame(1, Event::count());
    }

    #[Test]
    public function the_command_queues_a_job_per_connected_account(): void
    {
        Queue::fake();

        CalendarAccount::factory()->count(2)->create(['household_id' => $this->household->id]);
        CalendarAccount::factory()->create(['household_id' => $this->household->id, 'status' => 'disabled']);

        $this->artisan('sync:calendars')->assertSuccessful();

        Queue::assertPushed(SyncCalendarAccountJob::class, 2);
    }

    #[Test]
    public function the_command_says_so_when_nothing_is_connected(): void
    {
        $this->artisan('sync:calendars')
            ->expectsOutputToContain('No iCloud accounts connected')
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_can_target_one_account(): void
    {
        Queue::fake();

        $one = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        CalendarAccount::factory()->create(['household_id' => $this->household->id]);

        $this->artisan('sync:calendars', ['--account' => $one->id])->assertSuccessful();

        Queue::assertPushed(fn (SyncCalendarAccountJob $job) => $job->account->is($one));
    }

    #[Test]
    public function the_force_flag_reaches_the_job(): void
    {
        Queue::fake();

        CalendarAccount::factory()->create(['household_id' => $this->household->id]);

        $this->artisan('sync:calendars', ['--force' => true])->assertSuccessful();

        Queue::assertPushed(fn (SyncCalendarAccountJob $job) => $job->force === true);
    }

    #[Test]
    public function sync_jobs_run_on_the_sync_queue(): void
    {
        Queue::fake();

        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);

        SyncCalendarAccountJob::dispatch($account);

        // Horizon gives this queue priority over 'default'.
        Queue::assertPushedOn('sync', SyncCalendarAccountJob::class);
    }
}
