<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDisplayToken;
use App\Models\Household;
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
    public function a_valid_token_pairs_the_device_and_is_stripped_from_the_url(): void
    {
        $response = $this->get('/display?token=test-display-token');

        // The token must not linger in the address bar or browser history.
        $response->assertRedirect('http://localhost/display');
        $response->assertCookie(EnsureDisplayToken::COOKIE, 'test-display-token');
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
    public function the_pairing_redirect_keeps_https_behind_a_proxy(): void
    {
        // Forge terminates TLS and forwards plain HTTP. A redirect that
        // downgrades to http:// drops the Secure pairing cookie, leaving the
        // iPad in a permanent "not paired" loop.
        $this->get('https://hub.example.test/display?token=test-display-token', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '10.0.0.1',
        ])->assertRedirect('https://hub.example.test/display');
    }

    #[Test]
    public function it_fails_loudly_when_no_token_is_configured(): void
    {
        config(['familyhub.display.token' => '']);

        $this->get('/display')->assertStatus(503);
    }
}
