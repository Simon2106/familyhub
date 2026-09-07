<?php

namespace Tests\Feature\Attribution;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Models\User;
use App\Services\Attribution\EventAttributor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminPlacesTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $simon;

    protected Member $jenna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->simon = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Simon']);
        $this->jenna = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Jenna']);
    }

    #[Test]
    public function the_page_requires_a_signed_in_parent(): void
    {
        auth()->logout();

        $this->get('/admin/places')->assertRedirect('/login');
    }

    #[Test]
    public function a_place_can_be_created_with_aliases_and_members(): void
    {
        Livewire::test('admin.places')
            ->call('add')
            ->set('name', 'Sandy Gate')
            ->set('type', 'school')
            ->set('aliases', 'SG, Sandy Gate Primary')
            ->set('attached.'.$this->simon->id, true)
            ->set('automatic.'.$this->simon->id, true)
            ->call('save')
            ->assertHasNoErrors();

        $place = Place::firstOrFail();

        $this->assertSame('Sandy Gate', $place->name);
        $this->assertSame('school', $place->type);
        $this->assertEqualsCanonicalizing(['SG', 'Sandy Gate Primary'], $place->aliases->pluck('alias')->all());
        $this->assertSame([$this->simon->id], $place->members->pluck('id')->all());
        $this->assertTrue((bool) $place->members->first()->pivot->include_automatically);
    }

    #[Test]
    public function a_place_can_be_shared_with_one_member_opted_out(): void
    {
        Livewire::test('admin.places')
            ->call('add')
            ->set('name', 'Ice')
            ->set('type', 'work')
            ->set('aliases', 'IAAS')
            ->set('attached.'.$this->simon->id, true)
            ->set('automatic.'.$this->simon->id, true)
            ->set('attached.'.$this->jenna->id, true)
            ->set('automatic.'.$this->jenna->id, false)
            ->call('save')
            ->assertHasNoErrors();

        $place = Place::firstOrFail()->load('members');

        $this->assertTrue((bool) $place->members->firstWhere('id', $this->simon->id)->pivot->include_automatically);
        $this->assertFalse((bool) $place->members->firstWhere('id', $this->jenna->id)->pivot->include_automatically);
    }

    #[Test]
    public function attaching_a_member_defaults_them_to_automatic(): void
    {
        // Ticking someone almost always means "and include them".
        Livewire::test('admin.places')
            ->call('add')
            ->set('attached.'.$this->simon->id, true)
            ->assertSet('automatic.'.$this->simon->id, true);
    }

    #[Test]
    public function editing_a_place_loads_its_current_state(): void
    {
        $place = Place::factory()->work()->create(['household_id' => $this->household->id, 'name' => 'Ice']);
        $place->aliases()->create(['alias' => 'IAAS']);
        $place->members()->attach($this->simon->id, ['include_automatically' => true]);
        $place->members()->attach($this->jenna->id, ['include_automatically' => false]);

        Livewire::test('admin.places')
            ->call('edit', $place->id)
            ->assertSet('name', 'Ice')
            ->assertSet('type', 'work')
            ->assertSet('aliases', 'IAAS')
            ->assertSet('automatic.'.$this->simon->id, true)
            ->assertSet('automatic.'.$this->jenna->id, false);
    }

    #[Test]
    public function removing_an_alias_deletes_it(): void
    {
        $place = Place::factory()->create(['household_id' => $this->household->id, 'name' => 'Ice']);
        $place->aliases()->createMany([['alias' => 'IAAS'], ['alias' => 'Ice and a Slice']]);

        Livewire::test('admin.places')
            ->call('edit', $place->id)
            ->set('aliases', 'IAAS')
            ->call('save');

        $this->assertSame(['IAAS'], $place->fresh()->aliases->pluck('alias')->all());
    }

    #[Test]
    public function saving_a_place_re_attributes_existing_events(): void
    {
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id, 'member_id' => null]);
        $event = Event::factory()->create(['calendar_id' => $calendar->id, 'title' => 'SG inset day']);

        $this->assertCount(0, $event->members);

        Livewire::test('admin.places')
            ->call('add')
            ->set('name', 'Sandy Gate')
            ->set('type', 'school')
            ->set('aliases', 'SG')
            ->set('attached.'.$this->simon->id, true)
            ->set('automatic.'.$this->simon->id, true)
            ->call('save');

        $this->assertSame([$this->simon->id], $event->fresh()->members->pluck('id')->all());
    }

    #[Test]
    public function deleting_a_place_re_attributes_its_events(): void
    {
        $account = CalendarAccount::factory()->create(['household_id' => $this->household->id]);
        $calendar = Calendar::factory()->create(['calendar_account_id' => $account->id, 'member_id' => null]);
        $event = Event::factory()->create(['calendar_id' => $calendar->id, 'title' => 'SG inset day']);

        $place = Place::factory()->school()->create(['household_id' => $this->household->id, 'name' => 'Sandy Gate']);
        $place->aliases()->create(['alias' => 'SG']);
        $place->members()->attach($this->simon->id, ['include_automatically' => true]);

        // Attribute first, so there is something for the delete to undo.
        app(EventAttributor::class)->applyToHousehold($this->household->fresh());
        $this->assertSame([$this->simon->id], $event->fresh()->members->pluck('id')->all());

        Livewire::test('admin.places')->call('deletePlace', $place->id);

        $this->assertCount(0, $event->fresh()->members);
    }

    #[Test]
    public function another_households_place_cannot_be_edited(): void
    {
        $other = Place::factory()->create(['household_id' => Household::factory()->create()->id]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test('admin.places')->call('edit', $other->id);
    }

    #[Test]
    public function a_place_needs_a_name_and_a_valid_type(): void
    {
        Livewire::test('admin.places')
            ->call('add')
            ->set('name', '')
            ->set('type', 'nonsense')
            ->call('save')
            ->assertHasErrors(['name', 'type']);
    }
}
