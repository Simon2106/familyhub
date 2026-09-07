<?php

namespace Tests\Feature\Home;

use App\Models\HomeTile;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/**
 * A tap should look like it worked before Home Assistant has said so — and
 * should stop looking like it worked if Home Assistant never agrees.
 */
class OptimisticTileTest extends TestCase
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

    protected function tile(array $attributes = []): HomeTile
    {
        return HomeTile::factory()->create($attributes + ['household_id' => $this->household->id]);
    }

    /** A Home Assistant that takes the command and reports nothing new. */
    protected function unmoved(string $entityId, string $state = 'off'): void
    {
        FakeHomeAssistant::$states = [['entity_id' => $entityId, 'state' => $state, 'attributes' => []]];
    }

    #[Test]
    public function a_tapped_tile_shows_the_state_it_was_asked_for(): void
    {
        $this->unmoved('light.kitchen', 'off');
        $tile = $this->tile(['entity_id' => 'light.kitchen', 'name' => 'Kitchen spots']);

        $component = Livewire::test('home.panel')->call('press', $tile->id);

        // HA still says off, but the tile says on: we asked, and we are waiting.
        $this->assertTrue($component->instance()->showsOn($tile));
        $this->assertTrue($component->instance()->isPending($tile));
    }

    #[Test]
    public function the_browser_is_told_to_stop_holding_its_own_opinion(): void
    {
        $tile = $this->tile(['entity_id' => 'light.kitchen']);

        Livewire::test('home.panel')
            ->call('press', $tile->id)
            ->assertDispatched('tile-settled', tile: $tile->id);
    }

    #[Test]
    public function agreement_from_home_assistant_ends_the_waiting(): void
    {
        $this->unmoved('light.kitchen', 'off');
        $tile = $this->tile(['entity_id' => 'light.kitchen']);

        $component = Livewire::test('home.panel')->call('press', $tile->id);

        // The light comes on, as asked.
        $this->unmoved('light.kitchen', 'on');

        $component->call('$refresh');

        $this->assertFalse($component->instance()->isPending($tile));
        $this->assertTrue($component->instance()->showsOn($tile));
        $this->assertNull($component->instance()->noteFor($tile));
    }

    #[Test]
    public function a_light_that_never_answers_reverts_and_says_so(): void
    {
        $this->unmoved('light.kitchen', 'off');
        $tile = $this->tile(['entity_id' => 'light.kitchen', 'name' => 'Kitchen spots']);

        $component = Livewire::test('home.panel')->call('press', $tile->id);

        $this->assertTrue($component->instance()->showsOn($tile), 'Still hopeful.');

        // Five seconds later, HA still says off.
        $this->travel(6)->seconds();
        $component->call('$refresh');

        $this->assertFalse($component->instance()->showsOn($tile), 'Reverted to the truth.');
        $this->assertSame("Didn't respond", $component->instance()->noteFor($tile));
        $component->assertSee("Didn't respond");
    }

    #[Test]
    public function it_waits_a_few_seconds_before_giving_up(): void
    {
        $this->unmoved('light.kitchen', 'off');
        $tile = $this->tile(['entity_id' => 'light.kitchen']);

        $component = Livewire::test('home.panel')->call('press', $tile->id);

        // A Pi taking three seconds is slow, not broken.
        $this->travel(3)->seconds();
        $component->call('$refresh');

        $this->assertTrue($component->instance()->isPending($tile));
        $this->assertNull($component->instance()->noteFor($tile));
    }

    #[Test]
    public function the_note_does_not_stay_on_the_wall_forever(): void
    {
        $this->unmoved('light.kitchen', 'off');
        $tile = $this->tile(['entity_id' => 'light.kitchen']);

        $component = Livewire::test('home.panel')->call('press', $tile->id);

        $this->travel(6)->seconds();
        $component->call('$refresh');
        $this->assertNotNull($component->instance()->noteFor($tile));

        $this->travel(9)->seconds();
        $component->call('$refresh');

        $this->assertNull($component->instance()->noteFor($tile));
    }

    #[Test]
    public function tapping_again_clears_the_last_complaint(): void
    {
        $this->unmoved('light.kitchen', 'off');
        $tile = $this->tile(['entity_id' => 'light.kitchen']);

        $component = Livewire::test('home.panel')->call('press', $tile->id);
        $this->travel(6)->seconds();
        $component->call('$refresh');

        $this->assertNotNull($component->instance()->noteFor($tile));

        $component->call('press', $tile->id);

        $this->assertNull($component->instance()->noteFor($tile));
        $this->assertTrue($component->instance()->isPending($tile));
    }

    #[Test]
    public function a_call_that_could_not_be_sent_is_not_pretended_to_be_pending(): void
    {
        $this->unmoved('light.kitchen', 'off');
        $tile = $this->tile(['entity_id' => 'light.kitchen']);

        FakeHomeAssistant::$failStatus = 500;

        $component = Livewire::test('home.panel')->call('press', $tile->id);

        $this->assertFalse($component->instance()->isPending($tile), 'It never left the box.');
        $component->assertSee('Home Assistant answered 500');
    }

    #[Test]
    public function a_scene_has_no_state_to_be_optimistic_about(): void
    {
        FakeHomeAssistant::entity('scene.movie', 'unknown');
        $tile = $this->tile(['entity_id' => 'scene.movie', 'domain' => 'scene', 'name' => 'Movie']);

        $component = Livewire::test('home.panel')->call('press', $tile->id);

        $this->assertFalse($component->instance()->isPending($tile));
    }

    #[Test]
    public function a_blind_told_to_close_shows_closed_while_it_closes(): void
    {
        FakeHomeAssistant::entity('cover.blind', 'open', ['friendly_name' => 'Blind']);
        $tile = $this->tile(['entity_id' => 'cover.blind', 'domain' => 'cover', 'name' => 'Blind']);

        $component = Livewire::test('home.panel')->call('move', $tile->id, 'close');

        $this->assertTrue($component->instance()->isPending($tile));
        $this->assertFalse($component->instance()->showsOn($tile));
    }

    #[Test]
    public function stopping_a_blind_makes_no_promise_about_where_it_ends_up(): void
    {
        FakeHomeAssistant::entity('cover.blind', 'opening', ['friendly_name' => 'Blind']);
        $tile = $this->tile(['entity_id' => 'cover.blind', 'domain' => 'cover', 'name' => 'Blind']);

        $component = Livewire::test('home.panel')->call('move', $tile->id, 'stop');

        $this->assertFalse($component->instance()->isPending($tile));
    }

    #[Test]
    public function a_pending_tile_is_not_shown_as_missing(): void
    {
        // Mid-conversation is not the same as unreachable.
        FakeHomeAssistant::entity('light.kitchen', 'unavailable', ['friendly_name' => 'Kitchen spots']);
        $tile = $this->tile(['entity_id' => 'light.kitchen', 'name' => 'Kitchen spots']);

        Livewire::test('home.panel')
            ->call('press', $tile->id)
            ->assertDontSee('Not responding');
    }

    #[Test]
    public function a_tile_removed_while_waiting_does_not_leave_an_expectation_behind(): void
    {
        $this->unmoved('light.kitchen', 'off');
        $tile = $this->tile(['entity_id' => 'light.kitchen']);

        $component = Livewire::test('home.panel')->call('press', $tile->id);

        $tile->delete();
        $component->call('$refresh');

        $this->assertSame([], $component->instance()->expecting);
    }
}
