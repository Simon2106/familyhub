<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HouseholdSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_the_household_members_and_logins_from_env(): void
    {
        $this->seed(HouseholdSeeder::class);

        $household = Household::current();

        $this->assertSame('Test Household', $household->name);
        $this->assertSame(['Ada', 'Bea'], $household->members()->pluck('name')->all());

        $bea = Member::where('name', 'Bea')->firstOrFail();
        $this->assertTrue($bea->is_child);
        $this->assertTrue($bea->checkPin('4321'));
        $this->assertFalse($bea->checkPin('0000'));

        $ada = User::where('email', 'ada@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('secret-password', $ada->password));
        $this->assertSame($household->id, $ada->household_id);
    }

    #[Test]
    public function it_links_each_login_to_the_member_of_the_same_name(): void
    {
        $this->seed(HouseholdSeeder::class);

        $ada = User::where('email', 'ada@example.test')->firstOrFail();

        $this->assertSame('Ada', $ada->member->name);
    }

    #[Test]
    public function re_running_it_does_not_duplicate_or_reset_anything(): void
    {
        $this->seed(HouseholdSeeder::class);

        // Simulate a password changed after the initial seed.
        $ada = User::where('email', 'ada@example.test')->firstOrFail();
        $ada->update(['password' => 'changed-since-seeding']);

        $this->seed(HouseholdSeeder::class);

        $this->assertSame(1, Household::count());
        $this->assertSame(2, Member::count());
        $this->assertSame(1, User::count());

        // A re-seed must never hand the .env password back to a changed account.
        $this->assertTrue(Hash::check('changed-since-seeding', $ada->fresh()->password));
    }

    #[Test]
    public function a_members_pin_is_hashed_not_stored_in_the_clear(): void
    {
        $this->seed(HouseholdSeeder::class);

        $bea = Member::where('name', 'Bea')->firstOrFail();

        $this->assertNotSame('4321', $bea->pin);
        $this->assertNotContains('pin', array_keys($bea->toArray()));
    }
}
