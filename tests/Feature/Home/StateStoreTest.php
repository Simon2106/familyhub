<?php

namespace Tests\Feature\Home;

use App\Console\Commands\ListenToHomeAssistant;
use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\StateStore;
use App\Support\BuildVersion;
use App\Support\DeployWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/**
 * The websocket protocol handling, without a websocket.
 *
 * "Does a state_changed event update the right entity" is the question worth
 * asking, and it has nothing to do with sockets.
 */
class StateStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function store(array $rows = []): StateStore
    {
        $store = new StateStore;
        $store->seed($rows);

        return $store;
    }

    protected function stateChanged(string $entityId, ?array $newState): array
    {
        return [
            'type' => 'event',
            'event' => [
                'event_type' => 'state_changed',
                'data' => ['entity_id' => $entityId, 'new_state' => $newState],
            ],
        ];
    }

    #[Test]
    public function seeding_keys_the_states_by_entity(): void
    {
        $store = $this->store([
            ['entity_id' => 'light.kitchen', 'state' => 'off'],
            ['entity_id' => 'switch.lamp', 'state' => 'on'],
        ]);

        $this->assertSame(2, $store->count());
    }

    #[Test]
    public function a_row_with_no_entity_id_is_ignored(): void
    {
        $this->assertTrue($this->store([['state' => 'on'], 'nonsense'])->isEmpty());
    }

    #[Test]
    public function a_state_change_updates_that_entity_and_leaves_the_rest(): void
    {
        $store = $this->store([
            ['entity_id' => 'light.kitchen', 'state' => 'off'],
            ['entity_id' => 'switch.lamp', 'state' => 'on'],
        ]);

        $changed = $store->apply($this->stateChanged('light.kitchen', [
            'entity_id' => 'light.kitchen', 'state' => 'on',
        ]));

        $this->assertTrue($changed);

        $rows = collect($store->rows())->keyBy('entity_id');

        $this->assertSame('on', $rows['light.kitchen']['state']);
        $this->assertSame('on', $rows['switch.lamp']['state']);
    }

    #[Test]
    public function an_entity_it_has_never_seen_is_simply_added(): void
    {
        $store = $this->store();

        $store->apply($this->stateChanged('light.new', ['entity_id' => 'light.new', 'state' => 'on']));

        $this->assertSame(1, $store->count());
    }

    #[Test]
    public function an_entity_removed_from_home_assistant_is_dropped(): void
    {
        // Otherwise the wall shows the last state it ever had, forever.
        $store = $this->store([['entity_id' => 'light.kitchen', 'state' => 'on']]);

        $this->assertTrue($store->apply($this->stateChanged('light.kitchen', null)));
        $this->assertTrue($store->isEmpty());
    }

    #[Test]
    public function removing_something_already_gone_changes_nothing(): void
    {
        $store = $this->store();

        $this->assertFalse($store->apply($this->stateChanged('light.kitchen', null)));
    }

    #[Test]
    public function other_kinds_of_message_are_left_alone(): void
    {
        $store = $this->store([['entity_id' => 'light.kitchen', 'state' => 'on']]);

        $this->assertFalse($store->apply(['type' => 'result', 'success' => true]));
        $this->assertFalse($store->apply(['type' => 'event', 'event' => ['event_type' => 'call_service']]));
        $this->assertFalse($store->apply([]));
        $this->assertSame(1, $store->count());
    }

    #[Test]
    public function what_the_listener_holds_is_what_the_wall_reads(): void
    {
        FakeHomeAssistant::fake();
        config(['familyhub.homeassistant.cache_seconds' => 30]);

        $store = $this->store([['entity_id' => 'light.kitchen', 'state' => 'off', 'attributes' => []]]);
        $store->apply($this->stateChanged('light.kitchen', [
            'entity_id' => 'light.kitchen', 'state' => 'on', 'attributes' => [],
        ]));

        $ha = app(HomeAssistant::class);
        $ha->remember($store->rows());

        // Warm from the listener, so the wall never touches the Pi.
        $this->assertTrue($ha->state('light.kitchen')->isOn());
        Http::assertNothingSent();

        FakeHomeAssistant::reset();
    }

    #[Test]
    public function the_listener_checks_its_credentials_and_stops(): void
    {
        config(['familyhub.homeassistant.url' => null, 'familyhub.homeassistant.token' => null]);

        $this->artisan('familyhub:ha-listen', ['--once' => true])
            ->expectsOutputToContain('No HA_URL and HA_TOKEN are set.')
            ->assertFailed();
    }

    #[Test]
    public function a_once_run_that_cannot_connect_reports_failure(): void
    {
        // --once exists to prove the credentials work, so it has to fail when
        // they do not; returning success would make it useless as a check.
        config([
            'familyhub.homeassistant.url' => 'http://127.0.0.1:1',
            'familyhub.homeassistant.token' => 'nonsense',
        ]);

        $this->artisan('familyhub:ha-listen', ['--once' => true])->assertFailed();
    }

    #[Test]
    public function the_listener_says_which_build_it_started_on(): void
    {
        config([
            'familyhub.homeassistant.url' => 'http://127.0.0.1:1',
            'familyhub.homeassistant.token' => 'nonsense',
        ]);

        $this->artisan('familyhub:ha-listen', ['--once' => true])
            ->expectsOutputToContain('on build '.BuildVersion::current());
    }

    #[Test]
    public function the_listener_stops_when_it_is_asked_to(): void
    {
        // Supervisor sends SIGTERM and waits before resorting to SIGKILL.
        // Taking the hint closes the socket politely and leaves the cache
        // whole, rather than being shot half-way through writing it.
        $command = new ListenToHomeAssistant;
        $reflection = new \ReflectionClass($command);

        $stopping = $reflection->getProperty('stopping');
        $stopping->setAccessible(true);
        $this->assertFalse($stopping->getValue($command));

        $deploy = $reflection->getProperty('deploy');
        $deploy->setAccessible(true);
        $deploy->setValue($command, DeployWatch::start());

        $stopping->setValue($command, true);

        $superseded = $reflection->getMethod('superseded');
        $superseded->setAccessible(true);

        $this->assertTrue($superseded->invoke($command), 'A stop request must unwind every loop.');
    }

    #[Test]
    public function it_traps_the_signals_supervisor_actually_sends(): void
    {
        $source = file_get_contents(app_path('Console/Commands/ListenToHomeAssistant.php'));

        $this->assertStringContainsString('SIGTERM', $source);
        $this->assertStringContainsString('SIGINT', $source);
        $this->assertStringContainsString('function_exists(\'pcntl_signal\')', $source,
            'Guarded, because pcntl is not built into every PHP.');
    }
}
