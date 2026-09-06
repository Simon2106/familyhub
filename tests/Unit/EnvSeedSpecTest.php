<?php

namespace Tests\Unit;

use App\Support\EnvSeedSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EnvSeedSpecTest extends TestCase
{
    #[Test]
    public function it_parses_users(): void
    {
        $users = EnvSeedSpec::users('Ada|ada@example.test|pw1;Bob|bob@example.test|pw2');

        $this->assertCount(2, $users);
        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'pw1'], $users[0]);
    }

    #[Test]
    public function it_skips_incomplete_user_entries(): void
    {
        // A half-filled entry must not create a login with a blank password.
        $this->assertSame([], EnvSeedSpec::users('Ada|ada@example.test'));
        $this->assertSame([], EnvSeedSpec::users('Ada||pw'));
    }

    #[Test]
    public function it_tolerates_stray_separators_and_whitespace(): void
    {
        $users = EnvSeedSpec::users('  Ada | ada@example.test | pw1 ;;');

        $this->assertCount(1, $users);
        $this->assertSame('ada@example.test', $users[0]['email']);
    }

    #[Test]
    public function it_parses_members_and_flags_children(): void
    {
        $members = EnvSeedSpec::members('Ada|#2563EB|adult|;Bea|#16a34a|child|4321');

        $this->assertFalse($members[0]['is_child']);
        $this->assertSame('#2563eb', $members[0]['colour']);
        $this->assertNull($members[0]['pin']);

        $this->assertTrue($members[1]['is_child']);
        $this->assertSame('4321', $members[1]['pin']);
    }

    #[Test]
    public function an_adult_never_keeps_a_pin(): void
    {
        // A pin on an adult is meaningless and would be hashed for nothing.
        $members = EnvSeedSpec::members('Ada|#2563eb|adult|9999');

        $this->assertNull($members[0]['pin']);
    }

    #[Test]
    public function a_bad_colour_falls_back_rather_than_breaking_the_display(): void
    {
        $members = EnvSeedSpec::members('Ada|nonsense|adult|;Bea||adult|');

        $this->assertSame('#2563eb', $members[0]['colour']);
        $this->assertSame('#2563eb', $members[1]['colour']);
    }

    #[Test]
    public function an_empty_definition_yields_nothing(): void
    {
        $this->assertSame([], EnvSeedSpec::users(''));
        $this->assertSame([], EnvSeedSpec::members(''));
    }
}
