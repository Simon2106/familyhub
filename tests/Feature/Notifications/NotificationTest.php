<?php

namespace Tests\Feature\Notifications;

use App\Mail\NoticeDigest;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Chore;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\NoticeSent;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Chores\ChoreBoard;
use App\Services\Notifications\Notice;
use App\Services\Notifications\NoticeTriggers;
use App\Services\Notifications\NotificationSettings;
use App\Services\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Being told about things, once, and not in the middle of the night. */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected User $simon;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 18:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->simon = User::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function settings(): NotificationSettings
    {
        return app(NotificationSettings::class);
    }

    protected function wants(string ...$triggers): void
    {
        $this->settings()->put(
            $this->simon,
            collect($triggers)->mapWithKeys(fn ($t) => [$t => true])->all(),
            NotificationSettings::DEFAULT_LEAD,
        );

        $this->simon->refresh();
    }

    protected function notice(string $trigger = 'review_waiting', string $subject = 'x'): Notice
    {
        return new Notice($trigger, $subject, 'Something happened');
    }

    /* ------------------------------ wanting ------------------------------ */

    #[Test]
    public function everything_is_off_to_begin_with(): void
    {
        // A household buzzed about five things on day one is a household that
        // turns notifications off and never turns them back on.
        foreach (array_keys(NotificationSettings::TRIGGERS) as $trigger) {
            $this->assertFalse($this->settings()->wants($this->simon, $trigger));
        }

        $this->assertFalse($this->settings()->anything($this->simon));
    }

    #[Test]
    public function nothing_is_recorded_for_a_trigger_nobody_asked_for(): void
    {
        app(Notifier::class)->tell($this->simon, $this->notice());

        $this->assertSame(0, NoticeSent::count());
    }

    #[Test]
    public function a_lead_time_nobody_offers_falls_back(): void
    {
        $this->settings()->put($this->simon, [], 999);

        $this->assertSame(NotificationSettings::DEFAULT_LEAD, $this->settings()->leadMinutes($this->simon->fresh()));
    }

    /* ------------------------------- once -------------------------------- */

    #[Test]
    public function the_same_thing_is_never_told_twice(): void
    {
        $this->wants('review_waiting');

        $this->assertTrue(app(Notifier::class)->tell($this->simon, $this->notice()));
        $this->assertFalse(app(Notifier::class)->tell($this->simon, $this->notice()));

        $this->assertSame(1, NoticeSent::count());
    }

    #[Test]
    public function the_ledger_is_written_before_anything_is_sent(): void
    {
        // Which is what makes "never twice" true whether the push worked, was
        // held back by quiet hours, or went out in a digest instead.
        $this->wants('review_waiting');

        app(Notifier::class)->tell($this->simon, $this->notice());

        $record = NoticeSent::first();

        $this->assertNotNull($record);
        $this->assertNull($record->sent_at, 'Nothing was actually pushed — there are no devices.');
    }

    /* ---------------------------- quiet hours ---------------------------- */

    #[Test]
    public function nothing_goes_out_during_the_hours_the_wall_is_dimmed(): void
    {
        // The hours the wall dims itself are the hours nobody wants a phone
        // buzzing either.
        $this->household->setDarkMode('21:00', '06:30');

        $notifier = app(Notifier::class);

        $this->assertTrue($notifier->isQuiet($this->simon, CarbonImmutable::parse('2026-09-10 23:30', 'Europe/London')));
        $this->assertTrue($notifier->isQuiet($this->simon, CarbonImmutable::parse('2026-09-11 05:00', 'Europe/London')));
        $this->assertFalse($notifier->isQuiet($this->simon, CarbonImmutable::parse('2026-09-10 18:00', 'Europe/London')));
    }

    #[Test]
    public function a_window_that_does_not_cross_midnight_still_works(): void
    {
        $this->household->setDarkMode('13:00', '14:00');

        $notifier = app(Notifier::class);

        $this->assertTrue($notifier->isQuiet($this->simon, CarbonImmutable::parse('2026-09-10 13:30', 'Europe/London')));
        $this->assertFalse($notifier->isQuiet($this->simon, CarbonImmutable::parse('2026-09-10 12:30', 'Europe/London')));
    }

    #[Test]
    public function something_that_happens_at_night_waits_rather_than_vanishing(): void
    {
        $this->household->setDarkMode('21:00', '06:30');
        $this->wants('review_waiting');

        app(Notifier::class)->tell(
            $this->simon,
            $this->notice(),
            CarbonImmutable::parse('2026-09-10 23:30', 'Europe/London'),
        );

        $record = NoticeSent::first();

        $this->assertNotNull($record, 'It was recorded…');
        $this->assertNull($record->sent_at, '…but not sent.');
        $this->assertNull($record->digested_at, 'And it is still waiting for the digest.');
    }

    /* ------------------------------ triggers ----------------------------- */

    #[Test]
    public function an_event_is_announced_at_the_minute_the_reminder_is_due(): void
    {
        // The window is the due minute rather than "anything in the next
        // hour", so changing the lead time does not fire for the whole
        // afternoon at once.
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id]);

        Event::factory()->create([
            'calendar_id' => $calendar->id,
            'title' => 'Dentist',
            'start_at' => '2026-09-10 19:00:00',
            'end_at' => '2026-09-10 19:30:00',
        ]);

        $this->wants('event_reminder');

        $now = CarbonImmutable::parse('2026-09-10 18:00:00');
        $notices = app(NoticeTriggers::class)->forUser($this->simon, $this->household, $now);

        $this->assertCount(1, $notices->where('trigger', 'event_reminder'));
        $this->assertSame('Dentist', $notices->firstWhere('trigger', 'event_reminder')->title);

        // Half an hour earlier there is nothing yet.
        $early = app(NoticeTriggers::class)->forUser(
            $this->simon, $this->household, CarbonImmutable::parse('2026-09-10 17:30:00'),
        );

        $this->assertCount(0, $early->where('trigger', 'event_reminder'));
    }

    #[Test]
    public function a_standing_pile_is_mentioned_once_a_day_not_once_a_minute(): void
    {
        // A pile that grows from three to four is the same pile, and being
        // told again because of it is how people learn to ignore a
        // notification.
        $member = Member::factory()->create(['household_id' => $this->household->id, 'is_child' => true]);
        $chore = Chore::factory()->needingApproval()->create([
            'household_id' => $this->household->id, 'member_id' => $member->id,
        ]);
        app(ChoreBoard::class)->complete($chore, CarbonImmutable::parse('2026-09-10'), $member);

        $this->wants('approval_waiting');

        $at = CarbonImmutable::parse('2026-09-10 18:00', 'Europe/London');
        $notifier = app(Notifier::class);

        foreach (app(NoticeTriggers::class)->forUser($this->simon, $this->household, $at) as $notice) {
            $notifier->tell($this->simon, $notice, $at);
        }

        $later = $at->addMinutes(2);

        foreach (app(NoticeTriggers::class)->forUser($this->simon, $this->household, $later) as $notice) {
            $notifier->tell($this->simon, $notice, $later);
        }

        $this->assertSame(1, NoticeSent::where('trigger', 'approval_waiting')->count());
    }

    #[Test]
    public function a_standing_pile_is_only_mentioned_at_the_hour_somebody_can_act_on_it(): void
    {
        $this->wants('review_waiting');

        $notices = app(NoticeTriggers::class)->forUser(
            $this->simon, $this->household, CarbonImmutable::parse('2026-09-10 09:00', 'Europe/London'),
        );

        $this->assertCount(0, $notices->where('trigger', 'review_waiting'));
    }

    /* ------------------------------ devices ------------------------------ */

    #[Test]
    public function a_browser_signs_itself_up_and_subscribing_twice_replaces_the_row(): void
    {
        $this->actingAs($this->simon);

        $body = [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
            'label' => 'iPhone',
        ];

        $this->postJson('/app/push', $body)->assertOk();
        $this->postJson('/app/push', array_merge($body, ['label' => 'iPhone (installed)']))->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame('iPhone (installed)', PushSubscription::first()->device_label);
    }

    #[Test]
    public function a_device_can_be_forgotten(): void
    {
        $this->actingAs($this->simon);

        $this->postJson('/app/push', [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ])->assertOk();

        $this->deleteJson('/app/push', ['endpoint' => 'https://push.example/abc'])->assertOk();

        $this->assertSame(0, PushSubscription::count());
    }

    #[Test]
    public function subscribing_needs_a_login(): void
    {
        $this->postJson('/app/push', [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
        ])->assertUnauthorized();
    }

    /* ------------------------------- digest ------------------------------ */

    #[Test]
    public function anything_nobody_was_told_at_the_time_arrives_by_email(): void
    {
        Mail::fake();

        $this->wants('review_waiting');
        app(Notifier::class)->tell($this->simon, $this->notice());

        $this->artisan('familyhub:notice-digest')->assertSuccessful();

        Mail::assertSent(NoticeDigest::class);
        $this->assertNotNull(NoticeSent::first()->digested_at);
    }

    #[Test]
    public function a_digest_is_never_the_same_list_twice(): void
    {
        Mail::fake();

        $this->wants('review_waiting');
        app(Notifier::class)->tell($this->simon, $this->notice());

        $this->artisan('familyhub:notice-digest');
        $this->artisan('familyhub:notice-digest');

        Mail::assertSentCount(1);
    }

    #[Test]
    public function something_already_pushed_is_not_repeated_in_the_digest(): void
    {
        Mail::fake();

        $this->wants('review_waiting');
        app(Notifier::class)->tell($this->simon, $this->notice());

        NoticeSent::first()->forceFill(['sent_at' => now()])->save();

        $this->artisan('familyhub:notice-digest');

        Mail::assertNothingSent();
    }

    #[Test]
    public function a_digest_that_could_not_be_sent_is_late_rather_than_lost(): void
    {
        $this->wants('review_waiting');
        app(Notifier::class)->tell($this->simon, $this->notice());

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('no mailer'));

        $this->artisan('familyhub:notice-digest')->assertSuccessful();

        $this->assertNull(NoticeSent::first()->digested_at, 'Still waiting for the next attempt.');
    }

    #[Test]
    public function with_no_keys_configured_nothing_is_pushed_and_nothing_breaks(): void
    {
        config(['familyhub.push.public_key' => null, 'familyhub.push.private_key' => null]);

        $this->wants('review_waiting');

        $this->assertFalse(app(Notifier::class)->isConfigured());
        $this->assertTrue(app(Notifier::class)->tell($this->simon, $this->notice()), 'Still recorded.');
    }
}
