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
    public function it_fails_loudly_when_no_token_is_configured(): void
    {
        config(['familyhub.display.token' => '']);

        $this->get('/display')->assertStatus(503);
    }
}
