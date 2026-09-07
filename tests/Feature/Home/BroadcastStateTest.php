<?php

namespace Tests\Feature\Home;

use App\Console\Commands\ListenToHomeAssistant;
use App\Events\HomeStateChanged;
use App\Models\HomeTile;
use App\Models\Household;
use App\Models\User;
use App\Services\HomeAssistant\StateStore;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/** Pushing state to the wall, and still working when nothing is pushed. */
class BroadcastStateTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        FakeHomeAssistant::fake();
    }

    protected function tearDown(): void
    {
        FakeHomeAssistant::reset();

        parent::tearDown();
    }

    #[Test]
    public function the_nudge_carries_nothing_about_the_house(): void
    {
        $event = new HomeStateChanged;

        // A public channel is only safe because there is no payload: the wall
        // has no session to authenticate a private one with.
        $this->assertSame('home-assistant', $event->broadcastOn()->name);
        $this->assertSame('state-changed', $event->broadcastAs());
        $this->assertSame([], get_object_vars($event));
    }

    #[Test]
    public function it_broadcasts_immediately_rather_than_through_a_queue(): void
    {
        // The listener is not a queue worker, and a nudge that waits for one
        // arrives after the poll would have.
        $this->assertInstanceOf(
            ShouldBroadcastNow::class,
            new HomeStateChanged,
        );
    }

    #[Test]
    public function the_wall_re_reads_when_it_is_nudged(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'off', ['friendly_name' => 'Kitchen spots']);
        HomeTile::factory()->create(['household_id' => $this->household->id, 'entity_id' => 'light.kitchen']);

        $component = Livewire::test('home.panel')->assertSee('Off');

        // Somebody flicks the physical switch; the listener updates the cache.
        FakeHomeAssistant::$states = [['entity_id' => 'light.kitchen', 'state' => 'on', 'attributes' => []]];

        $component->call('refreshFromHome')->assertSee('On');
    }

    #[Test]
    public function the_poll_is_lazy_when_something_is_pushing_and_brisk_when_not(): void
    {
        config(['broadcasting.default' => 'reverb']);
        $this->assertSame('10s', Livewire::test('home.panel')->instance()->pollInterval);

        config(['broadcasting.default' => 'null']);
        $this->assertSame('3s', Livewire::test('home.panel')->instance()->pollInterval);
    }

    #[Test]
    public function the_poll_never_goes_away_entirely(): void
    {
        // A dropped socket must cost a few seconds, not the tab.
        config(['broadcasting.default' => 'reverb']);

        FakeHomeAssistant::entity('light.kitchen', 'on');
        HomeTile::factory()->create(['household_id' => $this->household->id, 'entity_id' => 'light.kitchen']);

        Livewire::test('home.panel')->assertSee('wire:poll.10s');
    }

    #[Test]
    public function the_listener_nudges_the_wall_when_something_changes(): void
    {
        Event::fake([HomeStateChanged::class]);

        $store = new StateStore;
        $store->seed([['entity_id' => 'light.kitchen', 'state' => 'off']]);

        $command = new ListenToHomeAssistant;
        $nudge = (new \ReflectionClass($command))->getMethod('nudge');
        $nudge->setAccessible(true);

        $nudge->invoke($command, true);

        Event::assertDispatched(HomeStateChanged::class);
    }

    #[Test]
    public function a_burst_of_changes_becomes_one_nudge(): void
    {
        // A house coming to life in the morning is a burst of state changes,
        // and the wall re-reads everything it shows in one go regardless.
        Event::fake([HomeStateChanged::class]);

        $command = new ListenToHomeAssistant;
        $nudge = (new \ReflectionClass($command))->getMethod('nudge');
        $nudge->setAccessible(true);

        foreach (range(1, 20) as $ignored) {
            $nudge->invoke($command, false);
        }

        Event::assertDispatchedTimes(HomeStateChanged::class, 1);
    }

    #[Test]
    public function a_broadcast_that_fails_does_not_stop_the_listener(): void
    {
        // Reverb being down must not stop the cache being kept warm, because
        // the wall's poll is still reading from it.
        Event::listen(HomeStateChanged::class, fn () => throw new \RuntimeException('Reverb is down'));

        $command = new ListenToHomeAssistant;
        $nudge = (new \ReflectionClass($command))->getMethod('nudge');
        $nudge->setAccessible(true);

        $nudge->invoke($command, true);

        $this->assertTrue(true, 'Reached here, so the throw was contained.');
    }

    #[Test]
    public function the_page_only_carries_a_reverb_key_when_there_is_one(): void
    {
        config(['broadcasting.default' => 'null']);
        $this->get('/app')->assertDontSee('reverb-key');

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
        ]);

        $this->get('/app')->assertSee('reverb-key');
    }
}
