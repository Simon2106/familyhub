<?php

namespace Tests\Feature\CalDav;

use App\Exceptions\CalDavException;
use App\Models\CalendarAccount;
use App\Models\Household;
use App\Services\CalDav\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeICloud;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeICloud::reset();

        parent::tearDown();
    }

    protected function connect(string $label = 'Simon', string $appleId = 'simon@example.com', string $pw = 'pw'): CalendarAccount
    {
        return app(AccountService::class)->connect(Household::factory()->create(), $label, $appleId, $pw);
    }

    #[Test]
    public function it_walks_root_to_principal_to_home_to_calendars(): void
    {
        FakeICloud::fake();

        $account = $this->connect();

        $this->assertSame(FakeICloud::PRINCIPAL, $account->principal_url);
        $this->assertSame(FakeICloud::HOME, $account->calendar_home_url);
        $this->assertSame('ok', $account->status);
    }

    #[Test]
    public function it_imports_only_calendars_that_hold_events(): void
    {
        FakeICloud::fake();

        // The home collection itself and the VTODO reminders list must not
        // become calendars on the wall.
        $this->assertSame(['Family'], $this->connect()->calendars()->pluck('name')->all());
    }

    #[Test]
    public function it_takes_the_apple_calendar_colour_and_trims_the_alpha(): void
    {
        FakeICloud::fake();

        // Apple sends #RRGGBBAA; our colour column holds #RRGGBB.
        $this->assertSame('#ff2968', $this->connect()->calendars()->first()->colour);
    }

    #[Test]
    public function it_notes_when_a_calendar_supports_sync_collection(): void
    {
        FakeICloud::fake();

        $this->assertTrue($this->connect()->calendars()->first()->supports_sync_collection);
    }

    #[Test]
    public function a_bad_app_specific_password_is_reported_clearly(): void
    {
        Http::fake(['caldav.icloud.com/*' => Http::response('', 401)]);

        $this->expectException(CalDavException::class);
        $this->expectExceptionMessage('app-specific password');

        $this->connect(pw: 'wrong');
    }

    #[Test]
    public function nothing_is_stored_when_credentials_are_rejected(): void
    {
        Http::fake(['caldav.icloud.com/*' => Http::response('', 401)]);

        try {
            $this->connect(pw: 'wrong');
        } catch (CalDavException) {
            // expected
        }

        $this->assertSame(0, CalendarAccount::count());
    }

    #[Test]
    public function a_refresh_keeps_our_own_choices_about_a_calendar(): void
    {
        FakeICloud::fake();

        $account = $this->connect();
        $calendar = $account->calendars()->first();
        $calendar->update(['colour' => '#123456', 'is_visible' => false]);

        app(AccountService::class)->refreshCalendars($account->fresh());

        $calendar->refresh();
        $this->assertSame('#123456', $calendar->colour);
        $this->assertFalse($calendar->is_visible);
    }

    #[Test]
    public function a_calendar_deleted_in_icloud_is_hidden_not_dropped(): void
    {
        FakeICloud::fake();

        $account = $this->connect();
        $calendar = $account->calendars()->first();

        // iCloud now reports an empty home.
        FakeICloud::$calendarListOverride = FakeICloud::multiStatus([]);

        app(AccountService::class)->refreshCalendars($account->fresh());

        // Kept, so re-adding it in iCloud does not lose the member assignment.
        $this->assertDatabaseHas('calendars', ['id' => $calendar->id, 'is_visible' => false]);
    }

    #[Test]
    public function several_icloud_accounts_can_coexist(): void
    {
        FakeICloud::fake();

        $household = Household::factory()->create();
        $service = app(AccountService::class);

        $one = $service->connect($household, 'Simon', 'simon@example.com', 'pw-1');
        $two = $service->connect($household, 'Partner', 'partner@example.com', 'pw-2');

        $this->assertSame(2, CalendarAccount::count());
        $this->assertSame('pw-1', $one->fresh()->credentials['password']);
        $this->assertSame('pw-2', $two->fresh()->credentials['password']);
    }

    #[Test]
    public function reconnecting_the_same_apple_id_updates_rather_than_duplicates(): void
    {
        FakeICloud::fake();

        $household = Household::factory()->create();
        $service = app(AccountService::class);

        $service->connect($household, 'Simon', 'simon@example.com', 'old-password');
        $service->connect($household, 'Simon renamed', 'simon@example.com', 'new-password');

        $this->assertSame(1, CalendarAccount::count());
        $this->assertSame('new-password', CalendarAccount::first()->credentials['password']);
        $this->assertSame('Simon renamed', CalendarAccount::first()->label);
    }

    #[Test]
    public function the_app_specific_password_is_encrypted_at_rest(): void
    {
        FakeICloud::fake();

        $this->connect(pw: 'super-secret-pw');

        $raw = DB::table('calendar_accounts')->value('credentials');

        $this->assertStringNotContainsString('super-secret-pw', (string) $raw);
    }
}
