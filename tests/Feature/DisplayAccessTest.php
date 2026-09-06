<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDisplayToken;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wall display must be reachable by the iPad without a login, but must not
 * be reachable by anyone who simply guesses the URL.
 */
class DisplayAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Household::factory()->create();
    }

    #[Test]
    public function it_rejects_an_unpaired_device(): void
    {
        $this->get('/display')
            ->assertForbidden()
            ->assertSee("isn't paired yet", escape: false);
    }

    #[Test]
    public function it_rejects_a_wrong_token(): void
    {
        $this->get('/display?token=not-the-token')->assertForbidden();
    }

    #[Test]
    public function a_valid_token_pairs_and_renders_in_the_same_response(): void
    {
        Member::factory()->create(['household_id' => Household::current()->id, 'name' => 'Ada']);

        $response = $this->get('/display?token=test-display-token');

        // No redirect: iOS gives the home-screen web app its own cookie jar, so
        // the pairing has to complete inside whichever context asked for it.
        $response->assertOk()
            ->assertSee('Ada')
            ->assertCookie(EnsureDisplayToken::COOKIE, 'test-display-token');
    }

    #[Test]
    public function the_token_stays_in_the_url_so_add_to_home_screen_captures_it(): void
    {
        $response = $this->get('/display?token=test-display-token')->assertOk();

        // A redirect here is what broke the PWA: it stripped the token before
        // "Add to Home Screen" could capture it.
        $this->assertNull($response->headers->get('Location'), 'Pairing must not redirect.');
    }

    #[Test]
    public function the_pairing_cookie_outlives_any_plausible_gap_between_glances(): void
    {
        $cookie = collect($this->get('/display?token=test-display-token')->headers->getCookies())
            ->first(fn ($c) => $c->getName() === EnsureDisplayToken::COOKIE);

        $this->assertNotNull($cookie);
        // A wall calendar that logs itself out after two hours is useless.
        $this->assertGreaterThan(now()->addMonths(6)->getTimestamp(), $cookie->getExpiresTime());
    }

    #[Test]
    public function the_display_page_carries_the_token_for_localstorage(): void
    {
        // The page mirrors the token into localStorage so a context that later
        // loses its cookie can re-pair itself without anyone reaching the wall.
        $this->get('/display?token=test-display-token')
            ->assertOk()
            ->assertSee('familyhub.display_token')
            ->assertSee('test-display-token', escape: false);
    }

    #[Test]
    public function a_cookie_paired_device_still_gets_the_token_to_store(): void
    {
        // Paired by cookie alone, with no token in the URL — it must still end
        // up with a copy in localStorage to recover from later.
        $this->withCookie(EnsureDisplayToken::COOKIE, 'test-display-token')
            ->get('/display')
            ->assertOk()
            ->assertSee('test-display-token', escape: false);
    }

    #[Test]
    public function the_installed_pwa_relaunches_at_a_url_that_carries_the_token(): void
    {
        // start_url is what iOS reopens; without the token the PWA would launch
        // into its own empty cookie jar and show "not paired" forever.
        $manifest = $this->get('/display/manifest.webmanifest?token=test-display-token')
            ->assertOk()
            ->json();

        $this->assertStringContainsString('token=test-display-token', $manifest['start_url']);
    }

    #[Test]
    public function the_display_manifest_never_leaks_the_token_to_a_stranger(): void
    {
        $this->get('/display/manifest.webmanifest')->assertForbidden();
        $this->get('/display/manifest.webmanifest?token=wrong')->assertForbidden();
    }

    #[Test]
    public function a_paired_device_stays_in_without_the_token(): void
    {
        $this->withCookie(EnsureDisplayToken::COOKIE, 'test-display-token')
            ->get('/display')
            ->assertOk();
    }

    #[Test]
    public function a_signed_in_parent_can_preview_the_display(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/display')
            ->assertOk();
    }

    #[Test]
    public function a_mismatched_token_is_a_403_never_a_404(): void
    {
        // A 404 from /display?token=... means the token was ACCEPTED and
        // something behind the middleware failed — never that the token was wrong.
        $this->get('/display?token=wrong')
            ->assertForbidden()
            ->assertSee("isn't paired yet", escape: false);
    }

    #[Test]
    public function pairing_an_unseeded_install_explains_itself_instead_of_404ing(): void
    {
        Household::query()->delete();

        $this->withCookie(EnsureDisplayToken::COOKIE, 'test-display-token')
            ->get('/display')
            ->assertStatus(503)
            ->assertSee('paired correctly')
            ->assertSee('familyhub:seed-demo --household-only');
    }

    #[Test]
    public function the_pwa_relaunch_url_keeps_https_behind_a_proxy(): void
    {
        // Forge terminates TLS and forwards plain HTTP. If the app does not
        // trust the proxy it builds http:// URLs, and an http start_url would
        // make the installed PWA relaunch insecurely — dropping the Secure
        // pairing cookie and stranding the iPad on "not paired".
        $manifest = $this->get('https://hub.example.test/display/manifest.webmanifest?token=test-display-token', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '10.0.0.1',
        ])->assertOk()->json();

        $this->assertStringStartsWith('https://', $manifest['start_url']);
    }

    #[Test]
    public function it_fails_loudly_when_no_token_is_configured(): void
    {
        config(['familyhub.display.token' => '']);

        $this->get('/display')->assertStatus(503);
    }
}
