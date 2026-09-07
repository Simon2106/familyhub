<?php

namespace Tests\Feature\Home;

use App\Services\HomeAssistant\EntityState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** What a tile says, and when it admits it does not know. */
class EntityStateTest extends TestCase
{
    protected function state(string $entityId, string $state, array $attributes = []): EntityState
    {
        return EntityState::fromArray(['entity_id' => $entityId, 'state' => $state, 'attributes' => $attributes]);
    }

    #[Test]
    public function a_light_is_on_or_off(): void
    {
        $this->assertTrue($this->state('light.kitchen', 'on')->isOn());
        $this->assertFalse($this->state('light.kitchen', 'off')->isOn());
        $this->assertSame('On', $this->state('light.kitchen', 'on')->summary());
    }

    #[Test]
    public function a_cover_counts_as_on_while_it_is_open_or_opening(): void
    {
        $this->assertTrue($this->state('cover.blind', 'open')->isOn());
        $this->assertTrue($this->state('cover.blind', 'opening')->isOn());
        $this->assertFalse($this->state('cover.blind', 'closed')->isOn());
        $this->assertSame('Closed', $this->state('cover.blind', 'closed')->summary());
    }

    #[Test]
    public function a_thermostat_shows_where_it_is_and_where_it_is_going(): void
    {
        $climate = $this->state('climate.hall', 'heat', [
            'current_temperature' => 18.5,
            'temperature' => 20.0,
        ]);

        $this->assertSame('18.5° → 20°', $climate->summary());
        $this->assertTrue($climate->isOn());
    }

    #[Test]
    public function a_thermostat_that_is_off_says_so(): void
    {
        $this->assertFalse($this->state('climate.hall', 'off')->isOn());
    }

    #[Test]
    public function something_home_assistant_has_lost_touch_with_is_not_pretended_to_be_off(): void
    {
        // A tile that renders a missing radiator valve as "off" is telling the
        // household something untrue.
        foreach (['unavailable', 'unknown'] as $state) {
            $entity = $this->state('climate.hall', $state);

            $this->assertTrue($entity->isUnavailable());
            $this->assertFalse($entity->isOn());
            $this->assertSame('Not responding', $entity->summary());
        }
    }

    #[Test]
    public function a_scene_invites_a_tap_rather_than_reporting_a_state(): void
    {
        $this->assertSame('Tap to run', $this->state('scene.movie', 'unknown')->summary());
        $this->assertSame('Tap to run', $this->state('script.bedtime', 'off')->summary());
    }

    #[Test]
    public function the_friendly_name_is_used_when_there_is_one(): void
    {
        $this->assertSame('Kitchen spots', $this->state('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'])->name());
        $this->assertSame('light.kitchen', $this->state('light.kitchen', 'on')->name());
    }
}
