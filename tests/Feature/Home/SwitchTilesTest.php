<?php

namespace Tests\Feature\Home;

use App\Jobs\SwitchGroupJob;
use App\Models\Household;
use App\Models\SwitchGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/** Group tiles, on the wall and on a phone. */
class SwitchTilesTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 18:00:00');

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        FakeHomeAssistant::fake();
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
            $group->entities()->create(['entity_id' => $entityId, 'name' => ucfirst(explode('.', $entityId)[1])]);
        }

        return $group->load('entities');
    }

    protected function services(): array
    {
        return array_map(fn (array $c) => basename($c['path']), FakeHomeAssistant::serviceCalls());
    }

    #[Test]
    public function a_group_is_one_tile_at_the_top_of_the_switches(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('switch.gym', 'on');

        $this->group();

        Livewire::test('home.panel')
            ->assertSee('Groups')
            ->assertSee('Lamps')
            ->assertSee('On · 2 switches');
    }

    #[Test]
    public function a_half_lit_group_says_so(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('switch.gym', 'off');

        $this->group();

        Livewire::test('home.panel')->assertSee('some on');
    }

    #[Test]
    public function tapping_an_instant_group_switches_it_now(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'off');
        FakeHomeAssistant::entity('switch.gym', 'off');

        $group = $this->group();

        Livewire::test('home.panel')->call('pressGroup', $group->id);

        $this->assertSame(['turn_on', 'turn_on'], $this->services());
    }

    #[Test]
    public function tapping_a_delayed_group_starts_a_countdown_with_a_cancel(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);

        Livewire::test('home.panel')
            ->call('pressGroup', $group->id)
            ->assertSee('Off in')
            ->assertSee('5:00')
            ->assertSee('Cancel');

        $this->assertSame([], $this->services());
        Queue::assertPushed(SwitchGroupJob::class);
    }

    #[Test]
    public function the_cancel_calls_it_off(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group(['off_delay' => 5], ['light.lamp']);

        Livewire::test('home.panel')
            ->call('pressGroup', $group->id)
            ->call('cancelGroup', $group->id)
            ->assertDontSee('Off in');

        $this->assertFalse($group->fresh()->hasPending());
    }

    #[Test]
    public function a_long_press_opens_the_members_so_one_can_be_toggled_alone(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('switch.gym', 'off');

        $group = $this->group();

        Livewire::test('home.panel')
            ->assertDontSee('Gym')
            ->call('openGroup', $group->id)
            ->assertSee('Lamp')
            ->assertSee('Gym')
            ->call('toggleMember', $group->id, 'switch.gym');

        $this->assertSame(['toggle'], $this->services());
    }

    #[Test]
    public function the_timings_can_be_changed_without_leaving_the_page(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');

        $group = $this->group([], ['light.lamp']);

        Livewire::test('home.panel')
            ->call('editTiming', $group->id)
            ->set('offDelay', 7)
            ->call('saveTiming');

        $this->assertSame(7, $group->fresh()->off_delay);
    }

    #[Test]
    public function a_nonsense_delay_is_brought_back_into_range(): void
    {
        $group = $this->group([], ['light.lamp']);

        Livewire::test('home.panel')
            ->call('editTiming', $group->id)
            ->set('offDelay', 99999)
            ->call('saveTiming');

        $this->assertSame(SwitchGroup::MAX_DELAY, $group->fresh()->off_delay);
    }

    #[Test]
    public function a_group_belonging_to_somebody_else_cannot_be_touched(): void
    {
        $theirs = SwitchGroup::factory()->create(['household_id' => Household::factory()->create()->id]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test('home.panel')->call('pressGroup', $theirs->id);
    }

    #[Test]
    public function the_phone_shows_the_same_panel(): void
    {
        // A group is a group wherever you are standing.
        FakeHomeAssistant::entity('light.lamp', 'on');
        $this->group();

        $this->get('/app/switches')->assertOk()->assertSee('Switches');
        Livewire::test('home.switches')->assertSee('Switches');
    }

    #[Test]
    public function with_no_groups_nothing_about_groups_appears(): void
    {
        Livewire::test('home.panel')->assertDontSee('Groups');
    }
}
