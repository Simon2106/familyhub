<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_signed_in_parent_can_view_horizon(): void
    {
        Household::factory()->create();

        // Laravel's generated gate is an empty allowlist, which would lock the
        // household out of their own queue dashboard in production.
        $this->assertTrue(Gate::forUser(User::factory()->create())->allows('viewHorizon'));
    }

    #[Test]
    public function a_guest_cannot_view_horizon(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
    }
}
