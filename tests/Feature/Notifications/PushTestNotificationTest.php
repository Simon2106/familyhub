<?php

namespace Tests\Feature\Notifications;

use App\Models\Household;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\Notice;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Send me a test notification", and the several ways nothing arrives.
 *
 * The whole point of the endpoint is that each failure says which one it was —
 * from the browser they all look identical, which is exactly why this was hard
 * to diagnose in the first place.
 */
class PushTestNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $household = Household::factory()->create();
        $this->user = User::factory()->create(['household_id' => $household->id]);

        $this->actingAs($this->user);

        config()->set('familyhub.push.public_key', 'test-public');
        config()->set('familyhub.push.private_key', 'test-private');
    }

    protected function subscribe(): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $this->user->id,
            'endpoint' => 'https://push.example/endpoint-1',
            'endpoint_hash' => PushSubscription::hash('https://push.example/endpoint-1'),
            'p256dh' => 'p256dh-key',
            'auth' => 'auth-key',
            'device_label' => 'iPhone (installed)',
        ]);
    }

    #[Test]
    public function it_says_so_when_the_server_has_no_keys(): void
    {
        config()->set('familyhub.push.public_key', null);
        config()->set('familyhub.push.private_key', null);

        $this->postJson(route('push.test'))
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'No push keys'));
    }

    #[Test]
    public function it_says_so_when_this_device_never_signed_up(): void
    {
        $this->postJson(route('push.test'))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'not signed up yet'));
    }

    #[Test]
    public function it_sends_when_there_is_somewhere_to_send_to(): void
    {
        $this->subscribe();

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('isConfigured')->andReturnTrue();
        $notifier->shouldReceive('push')
            ->once()
            ->withArgs(fn (User $user, Notice $notice) => $user->is($this->user)
                && $notice->trigger === 'test'
                && str_contains($notice->body, 'test notification'))
            ->andReturnTrue();

        $this->app->instance(Notifier::class, $notifier);

        $this->postJson(route('push.test'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Sent.'));
    }

    /** A dead endpoint is the case that looks most like "it just doesn't work". */
    #[Test]
    public function it_says_so_when_no_device_accepted_it(): void
    {
        $this->subscribe();

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('isConfigured')->andReturnTrue();
        $notifier->shouldReceive('push')->once()->andReturnFalse();

        $this->app->instance(Notifier::class, $notifier);

        $this->postJson(route('push.test'))
            ->assertStatus(502)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'no device accepted'));
    }

    #[Test]
    public function it_counts_the_devices_it_went_to(): void
    {
        $this->subscribe();

        PushSubscription::create([
            'user_id' => $this->user->id,
            'endpoint' => 'https://push.example/endpoint-2',
            'endpoint_hash' => PushSubscription::hash('https://push.example/endpoint-2'),
            'p256dh' => 'p256dh-key',
            'auth' => 'auth-key',
        ]);

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('isConfigured')->andReturnTrue();
        $notifier->shouldReceive('push')->once()->andReturnTrue();

        $this->app->instance(Notifier::class, $notifier);

        $this->postJson(route('push.test'))
            ->assertOk()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'all 2 of your devices'));
    }

    /**
     * It must not go through tell(): that honours quiet hours and the person's
     * own settings, which are both correct for a real notice and both wrong
     * for somebody standing there asking whether any of this works.
     */
    #[Test]
    public function a_test_ignores_quiet_hours_and_what_they_asked_to_be_told_about(): void
    {
        $this->subscribe();

        // Nothing switched on, and the middle of the household's dark hours.
        $this->user->forceFill(['notify_settings' => ['triggers' => [], 'lead' => 60]])->save();

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('isConfigured')->andReturnTrue();
        $notifier->shouldNotReceive('tell');
        $notifier->shouldReceive('push')->once()->andReturnTrue();

        $this->app->instance(Notifier::class, $notifier);

        $this->postJson(route('push.test'))->assertOk();
    }

    #[Test]
    public function a_stranger_cannot_ask_for_one(): void
    {
        auth()->logout();

        $this->postJson(route('push.test'))->assertStatus(401);
    }

    #[Test]
    public function the_page_offers_the_button_and_the_permission_state(): void
    {
        $this->get(route('notifications'))
            ->assertOk()
            ->assertSee('Send me a test notification')
            ->assertSee('Permission')
            // @js() escapes the slashes, so match the form actually rendered.
            ->assertSee(str_replace('/', '\\/', route('push.test')), false);
    }
}
