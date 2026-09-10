<?php

namespace Tests\Feature\Lists;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Search\HouseholdSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Lists beyond Shopping and To do. */
class MultipleListsTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function list(array $attributes = []): Checklist
    {
        return Checklist::factory()->create($attributes + [
            'household_id' => $this->household->id,
            'type' => 'custom',
            'name' => 'Packing',
        ]);
    }

    /* ------------------------------- making ------------------------------ */

    #[Test]
    public function a_named_colour_coded_list_can_be_made(): void
    {
        $joey = Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Joey']);

        Livewire::test('lists.page')
            ->call('newList')
            ->set('listName', 'Birthday wishlist')
            ->set('listColour', '#16a34a')
            ->set('listOwner', (string) $joey->id)
            ->set('onTheWall', true)
            ->call('saveList')
            ->assertHasNoErrors();

        $list = Checklist::firstWhere('name', 'Birthday wishlist');

        $this->assertSame('#16a34a', $list->colour);
        $this->assertSame($joey->id, $list->member_id);
        $this->assertTrue((bool) $list->is_home_list);
        $this->assertSame('custom', $list->type);
    }

    #[Test]
    public function a_nameless_list_is_refused(): void
    {
        Livewire::test('lists.page')
            ->call('newList')
            ->set('listName', '')
            ->call('saveList')
            ->assertHasErrors('listName');
    }

    #[Test]
    public function shopping_and_to_do_cannot_be_removed(): void
    {
        // Half the app points at them: the shopping generator and the capture
        // pipeline's to-dos would go with them.
        $shopping = Checklist::factory()->create([
            'household_id' => $this->household->id, 'type' => 'shopping', 'name' => 'Shopping',
        ]);

        Livewire::test('lists.page')
            ->call('deleteList', $shopping->id)
            ->assertSee('cannot be removed');

        $this->assertModelExists($shopping);
    }

    #[Test]
    public function a_list_somebody_made_can_be(): void
    {
        $list = $this->list();
        $list->items()->create(['title' => 'Sun cream']);

        Livewire::test('lists.page')->call('deleteList', $list->id);

        $this->assertModelMissing($list);
        $this->assertSame(0, ChecklistItem::count(), 'And what was on it.');
    }

    /* -------------------------------- items ------------------------------ */

    #[Test]
    public function things_are_added_ticked_and_removed(): void
    {
        $list = $this->list();

        $component = Livewire::test('lists.page')
            ->call('show', $list->id)
            ->set('itemTitle', 'Sun cream')
            ->call('addItem')
            ->assertSee('Sun cream')
            ->assertSet('itemTitle', '');

        $item = ChecklistItem::firstWhere('title', 'Sun cream');

        $component->call('toggleItem', $item->id);
        $this->assertTrue($item->fresh()->is_done);

        $component->call('deleteItem', $item->id);
        $this->assertModelMissing($item);
    }

    #[Test]
    public function an_empty_thing_is_not_added(): void
    {
        $list = $this->list();

        Livewire::test('lists.page')
            ->call('show', $list->id)
            ->set('itemTitle', '   ')
            ->call('addItem');

        $this->assertSame(0, ChecklistItem::count());
    }

    #[Test]
    public function items_are_reordered_one_step_at_a_time(): void
    {
        $list = $this->list();

        foreach (['Passports', 'Sun cream', 'Chargers'] as $title) {
            $list->items()->create(['title' => $title]);
        }

        $chargers = ChecklistItem::firstWhere('title', 'Chargers');

        $order = fn () => Livewire::test('lists.page')
            ->call('show', $list->id)
            ->instance()->items->pluck('title')->all();

        Livewire::test('lists.page')->call('show', $list->id)->call('move', $chargers->id, -1);

        $this->assertSame(['Passports', 'Chargers', 'Sun cream'], $order());
    }

    #[Test]
    public function moving_past_the_end_does_nothing_rather_than_wrapping(): void
    {
        $list = $this->list();
        $list->items()->create(['title' => 'Only thing']);

        $item = ChecklistItem::first();

        Livewire::test('lists.page')
            ->call('show', $list->id)
            ->call('move', $item->id, 1)
            ->call('move', $item->id, -1);

        $this->assertSame('Only thing', ChecklistItem::first()->title);
    }

    /* -------------------------------- reach ------------------------------ */

    #[Test]
    public function a_list_has_to_be_invited_onto_the_wall(): void
    {
        // A birthday wishlist is not something to leave on a kitchen wall.
        $this->list(['name' => 'Wishlist', 'is_home_list' => false]);
        $this->list(['name' => 'Packing', 'is_home_list' => true]);

        Livewire::test('display.lists', ['wallOnly' => true])
            ->assertSee('Packing')
            ->assertDontSee('Wishlist');
    }

    #[Test]
    public function but_the_phone_shows_them_all(): void
    {
        $this->list(['name' => 'Wishlist', 'is_home_list' => false]);

        Livewire::test('lists.page')->assertSee('Wishlist');
    }

    #[Test]
    public function search_finds_what_is_on_one_and_opens_it(): void
    {
        $list = $this->list(['name' => 'Packing']);
        $list->items()->create(['title' => 'Sun cream']);

        $found = app(HouseholdSearch::class)->search('sun cream', $this->household);

        $this->assertCount(1, $found);
        $this->assertSame('list', $found[0]->type);
        $this->assertSame('Lists', $found[0]->group());
        $this->assertStringContainsString('list='.$list->id, $found[0]->url);
    }

    #[Test]
    public function a_to_do_is_still_a_to_do_rather_than_a_list(): void
    {
        // Filing it under Lists would send the tap to the wrong page.
        $todo = Checklist::factory()->create([
            'household_id' => $this->household->id, 'type' => 'todo', 'name' => 'To do',
        ]);
        $todo->items()->create(['title' => 'Book the MOT']);

        $found = app(HouseholdSearch::class)->search('MOT', $this->household);

        $this->assertSame('todo', $found[0]->type);
    }
}
