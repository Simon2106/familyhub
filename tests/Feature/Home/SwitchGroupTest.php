<?php

namespace Tests\Feature\Home;

use App\Jobs\SwitchGroupJob;
use App\Models\Household;
use App\Models\SwitchGroup;
use App\Services\HomeAssistant\HomeAssistant;
use App\Services\HomeAssistant\SwitchBoard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/** Groups of switches, and the countdowns they run. */
class SwitchGroupTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 18:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);

        FakeHomeAssistant::fake();

        // Otherwise the sync driver runs every delayed job the instant it is
        // dispatched, and no countdown in these tests ever counts.
        Queue::fake([SwitchGroupJob::class]);
    }

    protected function tearDown(): void
    {
        FakeHomeAssistant::reset();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function group(array $attributes = [], array $entities = ['light.lamp', 'switch.gym']): SwitchGroup
    {
        $group = SwitchGroup::factory()->create($attributes + ['household_id' => $this->household->id]);

        foreach ($entities as $entityId) {
            $group->entities()->create(['entity_id' => $entityId, 'name' => $entityId]);
        }

        return $group->load('entities');
    }

    protected function board(): SwitchBoard
    {
        return app(SwitchBoard::class);
    }

    protected function states()
    {
        return app(HomeAssistant::class)->states(fresh: true);
    }

    protected function services(): array
    {
        return array_map(
            fn (array $call) => basename($call['path']).':'.($call['body']['entity_id'] ?? ''),
            FakeHomeAssistant::serviceCalls(),
        );
    }

    /* ------------------------------- state ------------------------------- */

    #[Test]
    public function a_group_is_on_off_or_honestly_mixed(): void
    {
        // A tile that says "on" when one of three lamps is lit tells the
        // household something untrue.
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('switch.gym', 'off');

        $group = $this->group();

        $this->assertSame('mixed', $this->board()->stateOf($group, $this->states()));

        FakeHomeAssistant::$states = [];
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('switch.gym', 'on');

        $this->assertSame('on', $this->board()->stateOf($group, $this->states()));
    }

    #[Test]
    public function a_group_nobody_can_reach_says_unknown_rather_than_off(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'unavailable');

        $group = $this->group(entities: ['light.lamp']);

        $this->assertSame('unknown', $this->board()->stateOf($group, $this->states()));
    }

    /* ------------------------------ instant ------------------------------ */

    #[Test]
    public function an_instant_group_switches_every_member_one_at_a_time(): void
    {
        // HA's own turn_on per entity: no HA group, no scene, so the grouping
        // stays something this household can change from the kitchen.
        FakeHomeAssistant::entity('light.lamp', 'off');
        FakeHomeAssistant::entity('switch.gym', 'off');

        $group = $this->group();

        $this->assertSame('on', $this->board()->press($group, $this->states()));

        $this->assertSame(
            ['turn_on:light.lamp', 'turn_on:switch.gym'],
            $this->services(),
        );
    }

    #[Test]
    public function a_mixed_group_turns_everything_off(): void
    {
        // What somebody reaching for a tile that says "2 of 3 on" means by it.
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('switch.gym', 'off');

        $group = $this->group();

        $this->assertSame('off', $this->board()->press($group, $this->states()));
        $this->assertSame(['turn_off:light.lamp', 'turn_off:switch.gym'], $this->services());
    }

    #[Test]
    public function one_unreachable_member_does_not_take_the_rest_with_it(): void
    {
        // Half a room lit beats a tap that appeared to do nothing.
        FakeHomeAssistant::entity('light.lamp', 'off');
        FakeHomeAssistant::entity('switch.gym', 'off');
        FakeHomeAssistant::$failStatus = 500;

        $group = $this->group();

        $this->board()->apply($group, 'on');

        $this->assertCount(2, FakeHomeAssistant::serviceCalls(), 'Both were still tried.');
    }

    /* ----------------------------- countdowns ---------------------------- */

    #[Test]
    public function a_delayed_direction_starts_a_countdown_instead_of_switching(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);

        $this->assertSame('pending', $this->board()->press($group, $this->states()));

        $group->refresh();

        $this->assertSame('off', $group->pending_direction);
        $this->assertSame(300, $group->secondsLeft());
        $this->assertSame([], $this->services(), 'Nothing switched yet.');

        Queue::assertPushed(SwitchGroupJob::class);
    }

    #[Test]
    public function the_countdown_is_the_servers_so_a_sleeping_wall_changes_nothing(): void
    {
        // The lamps go off whether or not anybody is still looking.
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);
        $this->board()->press($group, $this->states());

        $group->refresh();
        $deadline = $group->pending_fires_at->toIso8601String();

        // Five minutes later, on a wall that may well be asleep.
        CarbonImmutable::setTestNow('2026-09-09 18:05:00');

        (new SwitchGroupJob($group->id, 'off', $deadline))->handle($this->board());

        $this->assertSame(['turn_off:light.lamp'], $this->services());
        $this->assertFalse($group->fresh()->hasPending());
    }

    #[Test]
    public function tapping_again_calls_the_countdown_off(): void
    {
        // The way out of a mis-tap is the same tap, as everywhere on the wall.
        Queue::fake();
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);

        $this->board()->press($group, $this->states());
        $this->assertSame('cancelled', $this->board()->press($group->fresh()->load('entities'), $this->states()));

        $this->assertFalse($group->fresh()->hasPending());
        $this->assertSame([], $this->services());
    }

    #[Test]
    public function a_job_left_over_from_a_cancelled_countdown_does_nothing(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);
        $this->board()->press($group, $this->states());

        $group->refresh();
        $deadline = $group->pending_fires_at->toIso8601String();

        $this->board()->cancel($group);

        (new SwitchGroupJob($group->id, 'off', $deadline))->handle($this->board());

        $this->assertSame([], $this->services(), 'Nobody is waiting for this any more.');
    }

    #[Test]
    public function a_second_tap_that_starts_a_new_countdown_strands_the_first_job(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);
        $this->board()->press($group, $this->states());
        $stale = $group->fresh()->pending_fires_at->toIso8601String();

        // Cancel, then start another one a minute later.
        $this->board()->press($group->fresh()->load('entities'), $this->states());
        CarbonImmutable::setTestNow('2026-09-09 18:01:00');
        $this->board()->press($group->fresh()->load('entities'), $this->states());

        CarbonImmutable::setTestNow('2026-09-09 18:06:00');
        (new SwitchGroupJob($group->id, 'off', $stale))->handle($this->board());

        $this->assertSame([], $this->services());
        $this->assertTrue($group->fresh()->hasPending(), 'The newer countdown is untouched.');
    }

    /* --------------------------- the wall's nudge ------------------------ */

    /**
     * A broadcaster that cannot be built, which is what a Reverb that is
     * misconfigured or simply not running looks like from in here.
     */
    protected function breakBroadcasting(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => null,
            'broadcasting.connections.reverb.secret' => null,
            'broadcasting.connections.reverb.app_id' => null,
        ]);
    }

    /**
     * A broadcaster that answers, badly.
     *
     * Closer to what production actually did than a missing key: Reverb was
     * running, the server-side client was posting to the public hostname,
     * nginx handed that to Laravel, and the Pusher client choked on a 404
     * page — "Pusher error: <!DOCTYPE html>".
     */
    protected function breakBroadcastingMidFlight(): void
    {
        config(['broadcasting.default' => 'exploding']);

        Broadcast::extend('exploding', fn () => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new \RuntimeException('Pusher error: <!DOCTYPE html>');
            }
        });
    }

    #[Test]
    public function a_broadcaster_that_throws_mid_flight_does_not_break_the_tap(): void
    {
        // The devices are switched first and the wall is told afterwards, so a
        // nudge that fails costs the household nothing.
        FakeHomeAssistant::entity('light.lamp', 'off');

        $group = $this->group(entities: ['light.lamp']);

        $this->breakBroadcastingMidFlight();

        $this->assertSame('on', $this->board()->press($group, $this->states()));
        $this->assertSame(['turn_on:light.lamp'], $this->services(), 'The lamp still went on.');
    }

    #[Test]
    public function a_broadcaster_nobody_is_listening_on_does_not_break_the_tap(): void
    {
        // Reported from the wall: tapping a group gave a 500 while the lamps
        // had already been switched perfectly well. The event is
        // ShouldBroadcastNow, so it goes out inside the request — and this is
        // the only thing in the app that broadcasts from one.
        FakeHomeAssistant::entity('light.lamp', 'off');

        $group = $this->group(entities: ['light.lamp']);

        $this->breakBroadcasting();

        $this->assertSame('on', $this->board()->press($group, $this->states()));
        $this->assertSame(['turn_on:light.lamp'], $this->services(), 'And the lamp still went on.');
    }

    #[Test]
    public function nor_a_countdown_or_its_cancel(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);

        $this->breakBroadcasting();

        $this->assertSame('pending', $this->board()->press($group, $this->states()));
        $this->assertTrue($group->fresh()->hasPending());

        $this->board()->cancel($group->fresh());
        $this->assertFalse($group->fresh()->hasPending());
    }

    #[Test]
    public function nor_toggling_one_member(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(entities: ['light.lamp']);

        $this->breakBroadcasting();

        $this->board()->toggleMember($group, 'light.lamp');

        $this->assertSame(['toggle:light.lamp'], $this->services());
    }

    #[Test]
    public function a_group_with_nothing_in_it_yet_can_still_be_tapped(): void
    {
        // Made in /admin a moment ago and not filled in yet.
        $group = $this->group(entities: []);

        $this->breakBroadcasting();

        $this->assertSame('unknown', $this->board()->stateOf($group, $this->states()));
        $this->assertSame('off', $this->board()->press($group, $this->states()));
        $this->assertSame([], $this->services(), 'Nothing to switch, and nothing thrown.');
    }

    /* ------------------------------ members ------------------------------ */

    #[Test]
    public function a_countdown_reached_early_is_left_alone(): void
    {
        // A queue that ignores delays would otherwise turn "off in five
        // minutes" into "off", which is the one thing a countdown must not do.
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);
        $this->board()->press($group, $this->states());

        $group->refresh();

        (new SwitchGroupJob($group->id, 'off', $group->pending_fires_at->toIso8601String()))
            ->handle($this->board());

        $this->assertSame([], $this->services());
        $this->assertTrue($group->fresh()->hasPending(), 'And the countdown carries on.');
    }

    #[Test]
    public function one_member_can_be_toggled_on_its_own(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('switch.gym', 'off');

        $group = $this->group();

        $this->board()->toggleMember($group, 'switch.gym');

        $this->assertSame(['toggle:switch.gym'], $this->services());
    }

    #[Test]
    public function something_that_is_not_in_the_group_is_refused(): void
    {
        // The wall is a screen anybody can walk up to.
        FakeHomeAssistant::entity('light.lamp', 'on');

        $this->board()->toggleMember($this->group(entities: ['light.lamp']), 'light.somewhere_else');

        $this->assertSame([], $this->services());
    }
}
