<?php

namespace Tests\Feature\Home;

use App\Models\HomeTile;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/** The wall's Home tab, and the picker that fills it. */
class HomeTabTest extends TestCase
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

    #[Test]
    public function tiles_are_grouped_by_kind_with_the_room_on_each_tile(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'], area: 'Kitchen');
        FakeHomeAssistant::entity('switch.lamp', 'off', ['friendly_name' => 'Corner lamp'], area: 'Living room');

        $this->tile(['entity_id' => 'light.kitchen', 'name' => 'Kitchen spots', 'area' => 'Kitchen']);
        $this->tile(['entity_id' => 'switch.lamp', 'domain' => 'switch', 'name' => 'Corner lamp', 'area' => 'Living room']);

        Livewire::test('home.panel')
            ->assertSee('Lights')
            ->assertSee('Sockets & plugs')
            ->assertSee('Kitchen spots')
            ->assertSee('Corner lamp')
            // The room is still there, now on the tile rather than over it.
            ->assertSee('Kitchen')
            ->assertSee('Living room');
    }

    #[Test]
    public function a_section_with_nothing_in_it_is_not_shown(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on');
        $this->tile(['entity_id' => 'light.kitchen']);

        Livewire::test('home.panel')
            ->assertSee('Lights')
            ->assertDontSee('Blinds & covers')
            ->assertDontSee('Scenes & scripts')
            ->assertDontSee('Heating');
    }

    #[Test]
    public function the_sections_come_in_a_fixed_order(): void
    {
        FakeHomeAssistant::entity('scene.movie', 'unknown');
        FakeHomeAssistant::entity('climate.hall', 'heat');
        FakeHomeAssistant::entity('light.kitchen', 'on');

        // Added in the wrong order on purpose.
        $this->tile(['entity_id' => 'scene.movie', 'domain' => 'scene', 'name' => 'Movie']);
        $this->tile(['entity_id' => 'climate.hall', 'domain' => 'climate', 'name' => 'Hall']);
        $this->tile(['entity_id' => 'light.kitchen', 'name' => 'Kitchen spots']);

        $sections = Livewire::test('home.panel')->instance()->sections->keys()->all();

        $this->assertSame(['lights', 'heating', 'runnable'], $sections);
    }

    #[Test]
    public function a_tile_with_no_room_simply_has_no_sub_label(): void
    {
        FakeHomeAssistant::entity('light.spare', 'off');
        $this->tile(['entity_id' => 'light.spare', 'name' => 'Spare', 'area' => null]);

        Livewire::test('home.panel')
            ->assertSee('Spare')
            ->assertDontSee(HomeTile::UNGROUPED);
    }

    #[Test]
    public function each_domain_lands_in_the_section_it_belongs_to(): void
    {
        $expected = [
            'light' => 'lights',
            'switch' => 'sockets',
            'climate' => 'heating',
            'cover' => 'covers',
            'scene' => 'runnable',
            'script' => 'runnable',
            'sensor' => 'other',
        ];

        foreach ($expected as $domain => $section) {
            $this->assertSame($section, HomeTile::defaultSectionFor($domain), $domain);
        }
    }

    #[Test]
    public function resolving_a_section_works_on_a_tile_straight_out_of_the_database(): void
    {
        // A method named after its column is read by Eloquent as a
        // relationship, and the failure is a LogicException nowhere near the
        // cause. This is the shape that caught it: the column is absent from
        // the attributes of a model that was just created without it.
        $tile = $this->tile(['entity_id' => 'switch.sonoff', 'domain' => 'switch']);

        $this->assertFalse(array_key_exists('section_override', $tile->getAttributes()));
        $this->assertSame('sockets', $tile->section());
    }

    #[Test]
    public function a_plug_that_is_really_a_lamp_can_be_filed_under_lights(): void
    {
        FakeHomeAssistant::entity('switch.sonoff', 'on', ['friendly_name' => 'Corner lamp']);
        $tile = $this->tile([
            'entity_id' => 'switch.sonoff',
            'domain' => 'switch',
            'name' => 'Corner lamp',
            'section_override' => 'lights',
        ]);

        $this->assertSame('lights', $tile->section());

        $sections = Livewire::test('home.panel')->instance()->sections;

        $this->assertTrue($sections->get('lights')->contains('id', $tile->id));
        $this->assertNull($sections->get('sockets'));
    }

    #[Test]
    public function filing_a_plug_under_lights_does_not_change_what_tapping_it_does(): void
    {
        // It is still a switch entity in Home Assistant. Calling light.toggle
        // on it would simply fail.
        FakeHomeAssistant::entity('switch.sonoff', 'on');
        $tile = $this->tile(['entity_id' => 'switch.sonoff', 'domain' => 'switch', 'section_override' => 'lights']);

        Livewire::test('home.panel')->call('press', $tile->id);

        $this->assertSame('/api/services/switch/toggle', FakeHomeAssistant::serviceCalls()[0]['path']);
    }

    #[Test]
    public function a_blind_filed_elsewhere_keeps_its_buttons(): void
    {
        FakeHomeAssistant::entity('cover.blind', 'open', ['friendly_name' => 'Blind']);
        $tile = $this->tile([
            'entity_id' => 'cover.blind', 'domain' => 'cover', 'name' => 'Blind', 'section_override' => 'other',
        ]);

        Livewire::test('home.panel')
            ->assertSee('Other')
            ->assertSee('Open')
            ->call('move', $tile->id, 'close');

        $this->assertSame('/api/services/cover/close_cover', FakeHomeAssistant::serviceCalls()[0]['path']);
    }

    #[Test]
    public function a_nonsense_stored_kind_falls_back_to_the_domain(): void
    {
        $tile = $this->tile(['entity_id' => 'light.kitchen', 'section_override' => 'not-a-section']);

        $this->assertSame('lights', $tile->section());
    }

    #[Test]
    public function a_tile_shows_the_live_state(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots']);
        $this->tile(['entity_id' => 'light.kitchen']);

        Livewire::test('home.panel')->assertSee('On');
    }

    #[Test]
    public function tapping_a_light_asks_home_assistant_to_toggle_it(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on');
        $tile = $this->tile(['entity_id' => 'light.kitchen']);

        Livewire::test('home.panel')->call('press', $tile->id);

        $this->assertSame('/api/services/light/toggle', FakeHomeAssistant::serviceCalls()[0]['path']);
    }

    #[Test]
    public function a_blind_gets_buttons_rather_than_a_toggle(): void
    {
        FakeHomeAssistant::entity('cover.blind', 'open', ['friendly_name' => 'Blind']);
        $tile = $this->tile(['entity_id' => 'cover.blind', 'domain' => 'cover', 'name' => 'Blind']);

        Livewire::test('home.panel')
            ->assertSee('Open')
            ->assertSee('Close')
            ->call('move', $tile->id, 'close');

        $this->assertSame('/api/services/cover/close_cover', FakeHomeAssistant::serviceCalls()[0]['path']);
    }

    #[Test]
    public function a_thermostat_is_nudged_from_where_it_is_actually_set(): void
    {
        FakeHomeAssistant::entity('climate.hall', 'heat', [
            'friendly_name' => 'Hall',
            'current_temperature' => 18.0,
            'temperature' => 20.0,
        ]);
        $tile = $this->tile(['entity_id' => 'climate.hall', 'domain' => 'climate', 'name' => 'Hall']);

        Livewire::test('home.panel')->call('nudge', $tile->id, 0.5);

        $this->assertSame(20.5, FakeHomeAssistant::serviceCalls()[0]['body']['temperature']);
    }

    #[Test]
    public function something_home_assistant_has_lost_says_so_rather_than_looking_off(): void
    {
        FakeHomeAssistant::entity('climate.hall', 'unavailable', ['friendly_name' => 'Hall']);
        $this->tile(['entity_id' => 'climate.hall', 'domain' => 'climate', 'name' => 'Hall']);

        Livewire::test('home.panel')->assertSee('Not responding');
    }

    #[Test]
    public function a_tile_for_an_entity_that_no_longer_exists_is_not_pretended_to_be_off(): void
    {
        // Nothing in HA answers for it at all.
        $this->tile(['entity_id' => 'light.removed', 'name' => 'Removed']);

        Livewire::test('home.panel')
            ->assertSee('Removed')
            ->assertSee('Not responding');
    }

    #[Test]
    public function an_unreachable_pi_is_reported_on_the_wall_rather_than_thrown(): void
    {
        $this->tile(['entity_id' => 'light.kitchen']);
        FakeHomeAssistant::$failStatus = 500;

        Livewire::test('home.panel')->assertSee('Home Assistant answered 500');
    }

    #[Test]
    public function a_household_with_no_tiles_is_told_where_to_add_them(): void
    {
        Livewire::test('home.panel')->assertSee('Nothing on the wall yet');
    }

    #[Test]
    public function an_unconfigured_install_says_what_is_missing(): void
    {
        config(['familyhub.homeassistant.url' => null, 'familyhub.homeassistant.token' => null]);

        Livewire::test('home.panel')->assertSee('Home Assistant is not connected');
    }

    #[Test]
    public function the_wall_reads_state_once_however_many_tiles(): void
    {
        foreach (range(1, 8) as $n) {
            FakeHomeAssistant::entity("light.bulb_{$n}", 'on');
            $this->tile(['entity_id' => "light.bulb_{$n}", 'name' => "Bulb {$n}"]);
        }

        Livewire::test('home.panel');

        Http::assertSentCount(1);
    }

    /* ----------------------------- the picker ---------------------------- */

    #[Test]
    public function the_picker_lists_what_home_assistant_offers_grouped_by_room(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'], area: 'Kitchen');
        FakeHomeAssistant::entity('scene.movie', 'unknown', ['friendly_name' => 'Movie night'], area: 'Living room');

        Livewire::test('admin.home')
            ->assertSee('Kitchen spots')
            ->assertSee('Movie night')
            ->assertSee('Living room');
    }

    #[Test]
    public function adding_something_puts_it_on_the_wall_with_its_room(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'], area: 'Kitchen');

        Livewire::test('admin.home')->call('add', 'light.kitchen');

        $this->assertDatabaseHas('home_tiles', [
            'entity_id' => 'light.kitchen',
            'domain' => 'light',
            'name' => 'Kitchen spots',
            'area' => 'Kitchen',
        ]);
    }

    #[Test]
    public function something_already_on_the_wall_is_not_offered_again(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'], area: 'Kitchen');
        $this->tile(['entity_id' => 'light.kitchen', 'name' => 'Kitchen spots']);

        $available = Livewire::test('admin.home')->instance()->available->flatten(1);

        $this->assertCount(0, $available);
    }

    #[Test]
    public function the_picker_can_be_searched(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'], area: 'Kitchen');
        FakeHomeAssistant::entity('switch.lamp', 'off', ['friendly_name' => 'Corner lamp'], area: 'Living room');

        Livewire::test('admin.home')
            ->set('search', 'corner')
            ->assertSee('Corner lamp')
            ->assertDontSee('Kitchen spots');
    }

    #[Test]
    public function a_tile_can_be_given_a_name_the_household_actually_uses(): void
    {
        // "Sonoff 0x00124b" is not a name anyone taps twice.
        $tile = $this->tile(['entity_id' => 'switch.sonoff', 'name' => 'Sonoff 0x00124b']);

        Livewire::test('admin.home')
            ->call('startEditing', $tile->id)
            ->set('label', 'Kettle')
            ->call('saveTile');

        $this->assertSame('Kettle', $tile->fresh()->title());
    }

    #[Test]
    public function a_tiles_section_can_be_overridden_in_admin(): void
    {
        $tile = $this->tile(['entity_id' => 'switch.sonoff', 'domain' => 'switch', 'name' => 'Corner lamp']);

        $this->assertSame('sockets', $tile->section(), 'HA calls it a switch, so that is where it starts.');

        Livewire::test('admin.home')
            ->call('startEditing', $tile->id)
            ->set('section', 'lights')
            ->call('saveTile')
            ->assertHasNoErrors();

        $this->assertSame('lights', $tile->fresh()->section());
    }

    #[Test]
    public function clearing_the_override_returns_the_tile_to_the_default(): void
    {
        $tile = $this->tile(['entity_id' => 'switch.sonoff', 'domain' => 'switch', 'section_override' => 'lights']);

        Livewire::test('admin.home')
            ->call('startEditing', $tile->id)
            ->set('section', '')
            ->call('saveTile');

        // Stored as null, not as the derived value, so a tile nobody has had an
        // opinion about follows the defaults if those ever change.
        $this->assertNull($tile->fresh()->section_override);
        $this->assertSame('sockets', $tile->fresh()->section());
    }

    #[Test]
    public function a_section_that_does_not_exist_is_refused(): void
    {
        $tile = $this->tile();

        Livewire::test('admin.home')
            ->call('startEditing', $tile->id)
            ->set('section', 'nonsense')
            ->call('saveTile')
            ->assertHasErrors('section');
    }

    #[Test]
    public function the_picker_still_groups_by_room_because_that_is_how_you_find_things(): void
    {
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'], area: 'Kitchen');
        FakeHomeAssistant::entity('light.spare', 'off', ['friendly_name' => 'Spare'], area: null);

        Livewire::test('admin.home')
            ->assertSee('Kitchen')
            ->assertSee(HomeTile::UNGROUPED);
    }

    #[Test]
    public function refreshing_picks_up_a_room_renamed_in_home_assistant(): void
    {
        $tile = $this->tile(['entity_id' => 'light.kitchen', 'name' => 'Old name', 'area' => 'Kitchen']);
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots'], area: 'Cooking');

        Livewire::test('admin.home')->call('resync');

        $tile->refresh();

        $this->assertSame('Kitchen spots', $tile->name);
        $this->assertSame('Cooking', $tile->area);
    }

    #[Test]
    public function refreshing_keeps_a_tile_whose_entity_has_gone(): void
    {
        // The household should be told, not quietly corrected.
        $tile = $this->tile(['entity_id' => 'light.removed', 'name' => 'Removed']);

        Livewire::test('admin.home')->call('resync');

        $this->assertModelExists($tile);
    }

    #[Test]
    public function a_tile_can_be_taken_off_the_wall(): void
    {
        $tile = $this->tile();

        Livewire::test('admin.home')->call('remove', $tile->id);

        $this->assertModelMissing($tile);
    }

    #[Test]
    public function the_picker_needs_a_signed_in_parent(): void
    {
        auth()->logout();

        $this->get('/admin/home')->assertRedirect('/login');
    }
}
