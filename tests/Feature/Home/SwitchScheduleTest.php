<?php

namespace Tests\Feature\Home;

use App\Jobs\SwitchGroupJob;
use App\Models\Household;
use App\Models\SwitchGroup;
use App\Services\HomeAssistant\SwitchSchedules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/** Groups that turn themselves on and off. */
class SwitchScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        FakeHomeAssistant::fake();
        FakeHomeAssistant::entity('light.lamp', 'off');

        Queue::fake([SwitchGroupJob::class]);
    }

    protected function tearDown(): void
    {
        FakeHomeAssistant::reset();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function group(array $attributes): SwitchGroup
    {
        $group = SwitchGroup::factory()->create($attributes + ['household_id' => $this->household->id]);
        $group->entities()->create(['entity_id' => 'light.lamp', 'name' => 'Lamp']);

        return $group->fresh()->load('entities');
    }

    protected function runAt(string $localTime): array
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($localTime, 'Europe/London')->utc());

        return app(SwitchSchedules::class)->run($this->household);
    }

    protected function services(): array
    {
        return array_map(fn (array $c) => basename($c['path']), FakeHomeAssistant::serviceCalls());
    }

    #[Test]
    public function a_group_turns_itself_on_at_its_time(): void
    {
        $this->group(['on_trigger' => 'time', 'on_time' => '17:30']);

        $this->assertSame([], $this->runAt('2026-09-09 17:29'));
        $this->assertSame(['Lamps on'], $this->runAt('2026-09-09 17:30'));
        $this->assertSame(['turn_on'], $this->services());
    }

    #[Test]
    public function it_fires_once_a_day_and_not_once_a_minute(): void
    {
        $this->group(['on_trigger' => 'time', 'on_time' => '17:30']);

        $this->runAt('2026-09-09 17:30');
        $this->runAt('2026-09-09 17:31');
        $this->runAt('2026-09-09 17:45');

        $this->assertCount(1, $this->services());
    }

    #[Test]
    public function a_minute_that_was_missed_still_fires_shortly_after(): void
    {
        // A reboot, or a stalled worker, should not cost the household its
        // evening lights.
        $this->group(['on_trigger' => 'time', 'on_time' => '17:30']);

        $this->assertSame(['Lamps on'], $this->runAt('2026-09-09 17:44'));
    }

    #[Test]
    public function but_not_hours_later(): void
    {
        // A wall switched on at midnight must not act out the whole evening it
        // slept through.
        $this->group(['on_trigger' => 'time', 'on_time' => '17:30']);

        $this->assertSame([], $this->runAt('2026-09-09 23:30'));
    }

    #[Test]
    public function chosen_days_are_respected(): void
    {
        // 9 September 2026 is a Wednesday.
        $this->group(['on_trigger' => 'time', 'on_time' => '17:30', 'days' => [6, 7]]);

        $this->assertSame([], $this->runAt('2026-09-09 17:30'));
        $this->assertSame(['Lamps on'], $this->runAt('2026-09-12 17:30'), 'Saturday.');
    }

    #[Test]
    public function no_days_means_every_day(): void
    {
        $this->group(['on_trigger' => 'time', 'on_time' => '17:30', 'days' => []]);

        $this->assertSame(['Lamps on'], $this->runAt('2026-09-09 17:30'));
    }

    #[Test]
    public function a_scheduled_direction_honours_its_own_delay(): void
    {
        // "Off at eleven, after five minutes" is a countdown, not a switch.
        FakeHomeAssistant::$states = [];
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_trigger' => 'time', 'off_time' => '23:00', 'off_delay' => 5]);

        $this->assertSame(['Lamps off'], $this->runAt('2026-09-09 23:00'));
        $this->assertSame([], $this->services(), 'Nothing switched yet.');
        $this->assertTrue($group->fresh()->hasPending());
    }

    #[Test]
    public function sunset_comes_from_home_assistant_rather_than_from_us(): void
    {
        // HA already knows the house's latitude; two implementations of dusk
        // would disagree twice a year.
        FakeHomeAssistant::entity('sun.sun', 'above_horizon', [
            'next_setting' => '2026-09-09T18:42:00+00:00',
            'next_rising' => '2026-09-10T05:31:00+00:00',
        ]);

        $this->group(['on_trigger' => 'sunset']);

        $this->assertSame([], $this->runAt('2026-09-09 19:41'));
        $this->assertSame(['Lamps on'], $this->runAt('2026-09-09 19:42'), 'Local time, 18:42 UTC.');
    }

    #[Test]
    public function a_house_that_does_not_track_the_sun_simply_never_fires(): void
    {
        $this->group(['on_trigger' => 'sunset']);

        $this->assertSame([], $this->runAt('2026-09-09 19:42'));
    }

    #[Test]
    public function an_unscheduled_group_is_left_entirely_alone(): void
    {
        $this->group([]);

        $this->assertSame([], $this->runAt('2026-09-09 17:30'));
        $this->assertSame([], $this->services());
    }
}
