<?php

namespace Tests\Feature\Home;

use App\Jobs\SwitchGroupJob;
use App\Models\Household;
use App\Models\SwitchGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/**
 * Tapping a group twice.
 *
 * The reported bug: a group would switch once and then do nothing. Home
 * Assistant answers a service call before the lamps have obeyed it, so the
 * read taken straight afterwards still reports the state from before the tap
 * — and is then cached for a few seconds. The direction was decided from that
 * read, so the second tap sent the same command again and the lights stayed
 * where they were.
 *
 * A tile never had this because a tile toggles, which is relative. A group of
 * three has no toggle: every member is switched with an absolute turn_on or
 * turn_off, so the direction has to be decided from the tile somebody is
 * looking at.
 */
class SwitchTapTwiceTest extends TestCase
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

    protected function group(): SwitchGroup
    {
        $group = SwitchGroup::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Downstairs',
            'on_delay' => 0,
            'off_delay' => 0,
        ]);

        foreach (['light.lamp', 'light.hall'] as $entityId) {
            $group->entities()->create(['entity_id' => $entityId, 'name' => $entityId]);
        }

        return $group->load('entities');
    }

    protected function services(): array
    {
        return array_map(
            fn (array $call) => basename($call['path']).':'.($call['body']['entity_id'] ?? ''),
            FakeHomeAssistant::serviceCalls(),
        );
    }

    #[Test]
    public function a_second_tap_switches_the_other_way_although_home_assistant_still_says_off(): void
    {
        // The lamps never move: this is the Pi answering before they have,
        // held still so the window the bug lived in is the whole test.
        FakeHomeAssistant::entity('light.lamp', 'off');
        FakeHomeAssistant::entity('light.hall', 'off');

        $group = $this->group();

        Livewire::test('home.panel')
            ->call('pressGroup', $group->id)
            ->call('pressGroup', $group->id);

        $this->assertSame([
            'turn_on:light.lamp', 'turn_on:light.hall',
            'turn_off:light.lamp', 'turn_off:light.hall',
        ], $this->services());
    }

    #[Test]
    public function the_tile_shows_what_was_asked_for_while_it_waits(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'off');
        FakeHomeAssistant::entity('light.hall', 'off');

        $group = $this->group();

        Livewire::test('home.panel')
            ->call('pressGroup', $group->id)
            ->assertSet('expectingGroups', [$group->id => ['state' => 'on', 'at' => now()->timestamp]])
            ->assertSee('On · 2 switches');
    }

    #[Test]
    public function the_expectation_is_dropped_the_moment_it_comes_true(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'off');
        FakeHomeAssistant::entity('light.hall', 'off');

        $group = $this->group();

        $panel = Livewire::test('home.panel')->call('pressGroup', $group->id);

        FakeHomeAssistant::$states = [];
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('light.hall', 'on');

        $panel->call('$refresh')->assertSet('expectingGroups', []);
    }

    #[Test]
    public function a_group_that_never_answers_says_so_and_goes_back_to_the_truth(): void
    {
        // Reverting silently would look like the tap missed. Saying so is the
        // difference between a bug and a flat battery.
        FakeHomeAssistant::entity('light.lamp', 'off');
        FakeHomeAssistant::entity('light.hall', 'off');

        $group = $this->group();

        $panel = Livewire::test('home.panel')->call('pressGroup', $group->id);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(6));

        $panel->call('$refresh')
            ->assertSet('expectingGroups', [])
            ->assertSee('Didn&#039;t respond', false);
    }

    #[Test]
    public function a_countdown_is_not_something_to_be_optimistic_about(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'on');
        FakeHomeAssistant::entity('light.hall', 'on');

        $group = $this->group();
        $group->update(['off_delay' => 5]);

        Livewire::test('home.panel')
            ->call('pressGroup', $group->id)
            ->assertSet('expectingGroups', []);

        $this->assertSame([], $this->services());
    }

    #[Test]
    public function switching_one_member_forgets_what_the_whole_group_was_expected_to_be(): void
    {
        FakeHomeAssistant::entity('light.lamp', 'off');
        FakeHomeAssistant::entity('light.hall', 'off');

        $group = $this->group();

        Livewire::test('home.panel')
            ->call('pressGroup', $group->id)
            ->call('toggleMember', $group->id, 'light.lamp')
            ->assertSet('expectingGroups', []);
    }
}
