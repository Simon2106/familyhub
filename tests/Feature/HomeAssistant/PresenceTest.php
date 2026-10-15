<?php

namespace Tests\Feature\HomeAssistant;

use App\Models\Household;
use App\Models\User;
use App\Services\HomeAssistant\Client;
use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\Presence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether anybody is near the wall.
 *
 * The hardware is an Aqara FP2, which may not be fitted. Everything here is
 * written against configurable entities and a simulate switch for exactly
 * that reason.
 */
class PresenceTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    /** @var array<string, string> */
    protected array $entities = [];

    /** Whether the stub should answer as a Pi that is switched off. */
    protected bool $haIsDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'familyhub.homeassistant.url' => 'http://ha.test',
            'familyhub.homeassistant.token' => 'token',
            'familyhub.homeassistant.cache_seconds' => 0,
        ]);

        $this->household = Household::factory()->create();
        User::factory()->create(['household_id' => $this->household->id]);

        $this->household->setPresenceZones(
            'binary_sensor.fp2_near',
            'binary_sensor.fp2_room',
            'sensor.fp2_light',
        );

        // State-driven, because Http::fake() merges stubs and the first
        // match wins — a second call to it would keep answering with the
        // first set of states and a debounce test would prove nothing.
        Http::fake(['*/api/states' => fn () => $this->haIsDown
            ? Http::response('nope', 500)
            : Http::response(
                collect($this->entities)->map(fn (string $state, string $id) => [
                    'entity_id' => $id, 'state' => $state, 'attributes' => [],
                ])->values()->all()
            )]);
    }

    /** @param array<string, string> $states */
    protected function sensorsSay(array $states): void
    {
        $this->entities = $states;

        Cache::forget('ha:states');
    }

    protected function presence(): Presence
    {
        Cache::forget('ha:states');

        return new Presence(new HomeAssistant(new Client));
    }

    protected function at(string $when): CarbonImmutable
    {
        return CarbonImmutable::parse($when);
    }

    /* ------------------------------ debounce ---------------------------- */

    #[Test]
    public function walking_past_does_not_wake_the_wall(): void
    {
        // Two seconds of near before it counts, so a trip to the back door
        // does not light the kitchen up.
        $this->sensorsSay(['binary_sensor.fp2_near' => 'on', 'binary_sensor.fp2_room' => 'on']);

        $this->assertFalse($this->presence()->read($this->household, $this->at('2026-10-15 18:00:00'))['near']);
        $this->assertFalse($this->presence()->read($this->household, $this->at('2026-10-15 18:00:01'))['near']);
        $this->assertTrue($this->presence()->read($this->household, $this->at('2026-10-15 18:00:03'))['near']);
    }

    #[Test]
    public function standing_still_does_not_make_somebody_disappear(): void
    {
        // An mmWave sensor is confident about movement and much less so
        // about a person who has stopped moving, so the clear is slow.
        $this->sensorsSay(['binary_sensor.fp2_near' => 'on', 'binary_sensor.fp2_room' => 'on']);

        $this->presence()->read($this->household, $this->at('2026-10-15 18:00:00'));

        $this->assertTrue($this->presence()->read($this->household, $this->at('2026-10-15 18:00:05'))['near']);

        $this->sensorsSay(['binary_sensor.fp2_near' => 'off', 'binary_sensor.fp2_room' => 'on']);

        // Still near five seconds after the sensor dropped out.
        $this->assertTrue($this->presence()->read($this->household, $this->at('2026-10-15 18:00:10'))['near']);

        // Gone half a minute later.
        $this->assertFalse($this->presence()->read($this->household, $this->at('2026-10-15 18:00:35'))['near']);
    }

    #[Test]
    public function the_room_and_the_near_zone_are_answered_separately(): void
    {
        $this->sensorsSay(['binary_sensor.fp2_near' => 'off', 'binary_sensor.fp2_room' => 'on']);

        $this->presence()->read($this->household, $this->at('2026-10-15 18:00:00'));

        $read = $this->presence()->read($this->household, $this->at('2026-10-15 18:00:30'));

        $this->assertFalse($read['near'], 'Somebody is in, but not at the wall.');
        $this->assertTrue($read['room']);
    }

    /* ------------------------- degrading cleanly ------------------------ */

    #[Test]
    public function with_no_sensors_set_up_the_feature_is_simply_off(): void
    {
        $this->household->setPresenceZones(null, null, null);

        $read = $this->presence()->read($this->household->fresh());

        $this->assertFalse($read['configured']);
        $this->assertFalse($read['near']);
        $this->assertFalse($read['room']);
    }

    #[Test]
    public function home_assistant_being_unreachable_is_not_somebody_standing_there(): void
    {
        Cache::flush();

        $this->haIsDown = true;

        $read = (new Presence(new HomeAssistant(new Client)))->read($this->household);

        $this->assertFalse($read['readable']);
        $this->assertFalse($read['near']);
    }

    #[Test]
    public function a_sensor_that_has_gone_unavailable_reads_as_nobody_there(): void
    {
        $this->sensorsSay(['binary_sensor.fp2_near' => 'unavailable', 'binary_sensor.fp2_room' => 'unavailable']);

        $this->presence()->read($this->household, $this->at('2026-10-15 18:00:00'));

        $this->assertFalse($this->presence()->read($this->household, $this->at('2026-10-15 18:01:00'))['near']);
    }

    /* -------------------------- without hardware ------------------------ */

    #[Test]
    public function the_simulate_switch_works_without_the_sensor_being_fitted(): void
    {
        // The FP2 may never have been bought. The behaviour still has to be
        // something somebody can look at.
        $this->household->setSimulatedPresence('near');

        $read = $this->presence()->read($this->household->fresh());

        $this->assertTrue($read['simulated']);
        $this->assertTrue($read['near']);
        $this->assertTrue($read['room']);

        $this->household->setSimulatedPresence('room');

        $read = $this->presence()->read($this->household->fresh());

        $this->assertFalse($read['near']);
        $this->assertTrue($read['room']);

        $this->household->setSimulatedPresence('empty');

        $read = $this->presence()->read($this->household->fresh());

        $this->assertFalse($read['near']);
        $this->assertFalse($read['room']);
    }

    #[Test]
    public function a_simulated_read_counts_as_set_up(): void
    {
        // The wall ignores a presence read it has been told is not
        // configured, so a simulate switch that does not say it is
        // configured is a switch that tests nothing at all — which is
        // exactly how it shipped the first time.
        $this->household->setPresenceZones(null, null, null);
        $this->household->setSimulatedPresence('near');

        $read = $this->presence()->read($this->household->fresh());

        $this->assertTrue($read['configured']);
        $this->assertTrue($read['near']);
    }

    #[Test]
    public function simulating_beats_the_real_sensors_so_the_wall_can_be_tested(): void
    {
        $this->sensorsSay(['binary_sensor.fp2_near' => 'off', 'binary_sensor.fp2_room' => 'off']);

        $this->household->setSimulatedPresence('near');

        $this->assertTrue($this->presence()->read($this->household->fresh())['near']);
    }

    #[Test]
    public function the_lux_reading_comes_through_when_there_is_one(): void
    {
        $this->sensorsSay([
            'binary_sensor.fp2_near' => 'on',
            'binary_sensor.fp2_room' => 'on',
            'sensor.fp2_light' => '42.5',
        ]);

        $this->assertSame(42.5, $this->presence()->read($this->household)['lux']);
    }

    #[Test]
    public function the_wall_is_told_how_long_to_hold_and_when_to_sleep(): void
    {
        $this->household->setDeepSleepMinutes(25);

        $read = $this->presence()->read($this->household->fresh());

        $this->assertSame(Presence::HOLD_SECONDS, $read['holdSeconds']);
        $this->assertSame(25, $read['deepSleepMinutes']);
    }

    #[Test]
    public function admin_can_see_what_the_sensors_are_saying(): void
    {
        $this->sensorsSay(['binary_sensor.fp2_near' => 'on', 'binary_sensor.fp2_room' => 'on']);

        $explained = $this->presence()->explain($this->household);

        $this->assertSame('binary_sensor.fp2_near', $explained['entities']['near']);
        $this->assertTrue($explained['readable']);
        $this->assertArrayHasKey('listening', $explained);
    }
}
