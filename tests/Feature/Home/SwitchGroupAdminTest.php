<?php

namespace Tests\Feature\Home;

use App\Models\Household;
use App\Models\SwitchGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/** Making and shaping groups in /admin. */
class SwitchGroupAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        FakeHomeAssistant::fake();
        FakeHomeAssistant::entity('light.lamp', 'on', ['friendly_name' => 'Living room lamp'], area: 'Living room');
        FakeHomeAssistant::entity('switch.gym', 'off', ['friendly_name' => 'Gym socket'], area: 'Gym');
    }

    protected function tearDown(): void
    {
        FakeHomeAssistant::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_group_is_made_named_and_filled(): void
    {
        $component = Livewire::test('admin.home')
            ->set('newGroup', 'Lamps')
            ->call('addGroup');

        $group = SwitchGroup::firstWhere('name', 'Lamps');

        $this->assertNotNull($group);

        $component->call('addToGroup', $group->id, 'light.lamp')
            ->call('addToGroup', $group->id, 'switch.gym');

        $this->assertSame(['light.lamp', 'switch.gym'], $group->fresh()->entityIds());
        // The name it had when it was chosen, so a group reads with the Pi down.
        $this->assertSame('Living room lamp', $group->fresh()->entities->first()->name);
    }

    #[Test]
    public function the_same_switch_is_not_added_twice(): void
    {
        $group = SwitchGroup::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('admin.home')
            ->call('addToGroup', $group->id, 'light.lamp')
            ->call('addToGroup', $group->id, 'light.lamp');

        $this->assertCount(1, $group->fresh()->entities);
    }

    #[Test]
    public function the_two_directions_are_set_separately(): void
    {
        // On instantly, off after five, which is what a bank of lamps wants.
        $group = SwitchGroup::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('admin.home')
            ->call('editGroup', $group->id)
            ->set('onDelay', 0)
            ->set('offDelay', 5)
            ->call('saveGroup')
            ->assertHasNoErrors();

        $group->refresh();

        $this->assertTrue($group->isInstant('on'));
        $this->assertSame(5, $group->off_delay);
    }

    #[Test]
    public function a_schedule_keeps_only_the_time_it_uses(): void
    {
        // A sunset trigger with a stale 17:30 beside it would be a time nobody
        // set and nobody can see.
        $group = SwitchGroup::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('admin.home')
            ->call('editGroup', $group->id)
            ->set('onTrigger', 'sunset')
            ->set('offTrigger', 'time')
            ->set('offTime', '23:15')
            ->call('toggleDay', 6)
            ->call('toggleDay', 7)
            ->call('saveGroup');

        $group->refresh();

        $this->assertSame('sunset', $group->on_trigger);
        $this->assertNull($group->on_time);
        $this->assertSame('23:15', substr((string) $group->off_time, 0, 5));
        $this->assertSame([6, 7], $group->days);
    }

    #[Test]
    public function a_day_tapped_twice_comes_off_again(): void
    {
        $group = SwitchGroup::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('admin.home')
            ->call('editGroup', $group->id)
            ->set('onTrigger', 'time')
            ->call('toggleDay', 3)
            ->call('toggleDay', 3)
            ->call('saveGroup');

        $this->assertSame([], $group->fresh()->days);
    }

    #[Test]
    public function nonsense_is_refused(): void
    {
        $group = SwitchGroup::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('admin.home')
            ->call('editGroup', $group->id)
            ->set('groupName', '')
            ->call('saveGroup')
            ->assertHasErrors('groupName');

        Livewire::test('admin.home')
            ->call('editGroup', $group->id)
            ->set('offDelay', 9999)
            ->call('saveGroup')
            ->assertHasErrors('offDelay');
    }

    #[Test]
    public function deleting_a_group_leaves_the_switches_themselves_alone(): void
    {
        $group = SwitchGroup::factory()->create(['household_id' => $this->household->id]);
        $group->entities()->create(['entity_id' => 'light.lamp']);

        Livewire::test('admin.home')->call('deleteGroup', $group->id);

        $this->assertSame(0, SwitchGroup::count());
        $this->assertSame([], FakeHomeAssistant::serviceCalls(), 'Nothing was switched.');
    }

    #[Test]
    public function sunrise_and_sunset_only_appear_when_home_assistant_tracks_them(): void
    {
        $group = SwitchGroup::factory()->create(['household_id' => $this->household->id]);

        Livewire::test('admin.home')
            ->call('editGroup', $group->id)
            ->assertSee('once Home Assistant is tracking the sun');

        FakeHomeAssistant::entity('sun.sun', 'above_horizon', [
            'next_setting' => '2026-09-09T18:42:00+00:00',
        ]);

        Livewire::test('admin.home')
            ->call('editGroup', $group->id)
            ->assertSee('At sunset')
            ->assertDontSee('once Home Assistant is tracking the sun');
    }
}
