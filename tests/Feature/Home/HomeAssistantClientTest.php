<?php

namespace Tests\Feature\Home;

use App\Exceptions\HomeAssistantException;
use App\Services\HomeAssistant\Client;
use App\Services\HomeAssistant\HomeAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/** Talking to Home Assistant: the addresses, the reading, and the doing. */
class HomeAssistantClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeHomeAssistant::fake();
    }

    protected function tearDown(): void
    {
        FakeHomeAssistant::reset();

        parent::tearDown();
    }

    protected function ha(): HomeAssistant
    {
        return app(HomeAssistant::class);
    }

    #[Test]
    public function the_configured_url_is_used_exactly_as_given(): void
    {
        // This install answers on 80, not the 8123 everyone assumes. A driver
        // that helpfully appends a port cannot talk to this house at all.
        FakeHomeAssistant::entity('light.kitchen', 'on');

        $this->ha()->states();

        Http::assertSent(fn ($request) => $request->url() === 'http://homeassistant.local/api/states');
    }

    #[Test]
    public function a_url_that_does_carry_a_port_keeps_it(): void
    {
        $client = new Client('http://10.0.0.5:8123/', 'token');

        $this->assertSame('ws://10.0.0.5:8123/api/websocket', $client->websocketUrl());
    }

    #[Test]
    public function the_websocket_address_follows_the_base_url(): void
    {
        $this->assertSame(
            'ws://homeassistant.local/api/websocket',
            (new Client('http://homeassistant.local', 't'))->websocketUrl(),
        );

        $this->assertSame(
            'wss://ha.example.com/api/websocket',
            (new Client('https://ha.example.com/', 't'))->websocketUrl(),
        );
    }

    #[Test]
    public function a_url_without_a_scheme_is_refused_rather_than_guessed(): void
    {
        $this->expectException(HomeAssistantException::class);
        $this->expectExceptionMessage('must start with http:// or https://');

        (new Client('homeassistant.local', 't'))->websocketUrl();
    }

    #[Test]
    public function the_token_is_sent_as_a_bearer(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on');

        $this->ha()->states();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.FakeHomeAssistant::TOKEN));
    }

    #[Test]
    public function only_the_domains_with_tiles_are_read(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on');
        FakeHomeAssistant::entity('switch.lamp', 'off');
        FakeHomeAssistant::entity('climate.hall', 'heat');
        FakeHomeAssistant::entity('cover.blind', 'open');
        FakeHomeAssistant::entity('scene.movie', 'unknown');
        FakeHomeAssistant::entity('script.bedtime', 'off');
        // The two hundred entities a Zigbee network invents.
        FakeHomeAssistant::entity('sensor.kitchen_temperature', '19.5');
        FakeHomeAssistant::entity('automation.morning', 'on');

        $states = $this->ha()->states();

        $this->assertCount(6, $states);
        $this->assertNull($states->get('sensor.kitchen_temperature'));
    }

    #[Test]
    public function an_unset_install_says_so_rather_than_failing_obscurely(): void
    {
        config(['familyhub.homeassistant.url' => null, 'familyhub.homeassistant.token' => null]);

        $this->assertFalse($this->ha()->isConfigured());

        $this->expectException(HomeAssistantException::class);
        $this->expectExceptionMessage('No HA_URL and HA_TOKEN are set');

        $this->ha()->states();
    }

    #[Test]
    public function a_refused_token_says_what_to_do_about_it(): void
    {
        FakeHomeAssistant::$failStatus = 401;

        $this->expectException(HomeAssistantException::class);
        $this->expectExceptionMessage('Create a new long-lived access token');

        $this->ha()->states();
    }

    #[Test]
    public function a_pi_that_is_switched_off_is_reported_plainly(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('Connection refused')]);

        $this->expectException(HomeAssistantException::class);
        $this->expectExceptionMessage('Home Assistant could not be reached');

        (new HomeAssistant(new Client(FakeHomeAssistant::URL, 'token')))->states();
    }

    #[Test]
    public function tapping_a_light_toggles_it(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on');

        $this->ha()->toggle('light.kitchen');

        $this->assertSame(
            [['path' => '/api/services/light/toggle', 'body' => ['entity_id' => 'light.kitchen']]],
            FakeHomeAssistant::serviceCalls(),
        );
    }

    #[Test]
    public function a_scene_is_run_rather_than_toggled(): void
    {
        FakeHomeAssistant::entity('scene.movie_night', 'unknown');

        $this->ha()->toggle('scene.movie_night');

        // A scene has no off. Tapping it means "do it".
        $this->assertSame('/api/services/scene/turn_on', FakeHomeAssistant::serviceCalls()[0]['path']);
    }

    #[Test]
    public function a_script_is_run_the_same_way(): void
    {
        FakeHomeAssistant::entity('script.bedtime', 'off');

        $this->ha()->toggle('script.bedtime');

        $this->assertSame('/api/services/script/turn_on', FakeHomeAssistant::serviceCalls()[0]['path']);
    }

    #[Test]
    public function a_covers_buttons_map_to_its_services(): void
    {
        FakeHomeAssistant::entity('cover.blind', 'open');

        foreach (['open' => 'open_cover', 'close' => 'close_cover', 'stop' => 'stop_cover'] as $action => $service) {
            $this->ha()->cover('cover.blind', $action);

            $this->assertSame("/api/services/cover/{$service}", end(FakeHomeAssistant::$calls)['path']);
        }
    }

    #[Test]
    public function a_thermostat_is_held_between_sane_temperatures(): void
    {
        FakeHomeAssistant::entity('climate.hall', 'heat');

        $this->ha()->setTemperature('climate.hall', 99);
        $this->assertSame(30, end(FakeHomeAssistant::$calls)['body']['temperature']);

        $this->ha()->setTemperature('climate.hall', -5);
        $this->assertSame(5, end(FakeHomeAssistant::$calls)['body']['temperature']);

        $this->ha()->setTemperature('climate.hall', 19.5);
        $this->assertSame(19.5, end(FakeHomeAssistant::$calls)['body']['temperature']);
    }

    #[Test]
    public function the_pickable_list_carries_the_room_each_thing_is_in(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'], area: 'Kitchen');
        FakeHomeAssistant::entity('switch.lamp', 'off', ['friendly_name' => 'Corner lamp'], area: 'Living room');
        FakeHomeAssistant::entity('light.spare', 'off', ['friendly_name' => 'Spare'], area: null);

        $pickable = $this->ha()->pickable();

        $this->assertSame('Kitchen spots', $pickable->firstWhere('entity_id', 'light.kitchen')['name']);
        $this->assertSame('Kitchen', $pickable->firstWhere('entity_id', 'light.kitchen')['area']);
        $this->assertNull($pickable->firstWhere('entity_id', 'light.spare')['area']);
    }

    #[Test]
    public function the_room_list_is_one_request_however_many_entities(): void
    {
        foreach (range(1, 40) as $n) {
            FakeHomeAssistant::entity("light.bulb_{$n}", 'off', area: 'Kitchen');
        }

        $this->ha()->pickable();

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_dozen_tiles_do_not_make_a_dozen_requests(): void
    {
        config(['familyhub.homeassistant.cache_seconds' => 30]);
        FakeHomeAssistant::entity('light.kitchen', 'on');

        // A wall full of tiles must not hammer a Raspberry Pi.
        $this->ha()->states();
        $this->ha()->states();
        $this->ha()->states();

        Http::assertSentCount(1);
    }

    #[Test]
    public function acting_on_something_clears_the_state_it_just_changed(): void
    {
        config(['familyhub.homeassistant.cache_seconds' => 30]);
        FakeHomeAssistant::entity('light.kitchen', 'off');

        $this->assertFalse($this->ha()->state('light.kitchen')->isOn());

        FakeHomeAssistant::$states = [['entity_id' => 'light.kitchen', 'state' => 'on', 'attributes' => []]];
        $this->ha()->toggle('light.kitchen');

        // The next read must not serve the state from before the tap.
        $this->assertTrue($this->ha()->state('light.kitchen')->isOn());
    }
}
