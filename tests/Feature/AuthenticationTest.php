<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_login_screen_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    #[Test]
    public function a_household_member_can_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test', 'password' => 'secret-password']);

        Livewire::test('auth.login')
            ->set('email', 'ada@example.test')
            ->set('password', 'secret-password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect('/app');

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'ada@example.test', 'password' => 'secret-password']);

        Livewire::test('auth.login')
            ->set('email', 'ada@example.test')
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function repeated_failures_are_throttled(): void
    {
        User::factory()->create(['email' => 'ada@example.test', 'password' => 'secret-password']);

        $component = Livewire::test('auth.login')
            ->set('email', 'ada@example.test')
            ->set('password', 'wrong');

        foreach (range(1, 5) as $ignored) {
            $component->call('login');
        }

        // The sixth attempt is refused before the credentials are even checked.
        $component->set('password', 'secret-password')->call('login')->assertHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function there_is_no_registration_route(): void
    {
        // FamilyHub is a single household; accounts come from .env and /admin.
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    #[Test]
    public function a_guest_is_sent_to_login_from_the_app_and_admin(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
    }

    #[Test]
    public function a_member_can_sign_out(): void
    {
        Household::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
