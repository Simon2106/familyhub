<?php

namespace Tests\Feature;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Models\Member;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ticking_an_item_persists_it(): void
    {
        $household = Household::factory()->create();
        $list = Checklist::factory()->create(['household_id' => $household->id, 'name' => 'Shopping']);
        $item = ChecklistItem::factory()->create(['checklist_id' => $list->id, 'title' => 'Milk']);

        Livewire::test('display.lists')->call('toggle', $item->id);

        $item->refresh();
        $this->assertTrue($item->is_done);
        $this->assertNotNull($item->done_at);
    }

    #[Test]
    public function ticking_a_done_item_again_clears_it(): void
    {
        $household = Household::factory()->create();
        $list = Checklist::factory()->create(['household_id' => $household->id]);
        $item = ChecklistItem::factory()->done()->create(['checklist_id' => $list->id]);

        Livewire::test('display.lists')->call('toggle', $item->id);

        $item->refresh();
        $this->assertFalse($item->is_done);
        $this->assertNull($item->done_at);
        $this->assertNull($item->done_by_member_id);
    }

    #[Test]
    public function it_records_who_ticked_an_item(): void
    {
        $household = Household::factory()->create();
        $member = Member::factory()->create(['household_id' => $household->id]);
        $list = Checklist::factory()->create(['household_id' => $household->id]);
        $item = ChecklistItem::factory()->create(['checklist_id' => $list->id]);

        $item->toggle($member);

        $this->assertSame($member->id, $item->fresh()->done_by_member_id);
    }

    #[Test]
    public function it_refuses_to_touch_another_households_item(): void
    {
        // Household::current() resolves the first household, so this item
        // belongs to a list the display must not be able to reach.
        Household::factory()->create();
        $other = Household::factory()->create();
        $list = Checklist::factory()->create(['household_id' => $other->id]);
        $item = ChecklistItem::factory()->create(['checklist_id' => $list->id]);

        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::test('display.lists')->call('toggle', $item->id);
        } finally {
            $this->assertFalse($item->fresh()->is_done);
        }
    }

    #[Test]
    public function clearing_done_removes_only_the_ticked_items(): void
    {
        $household = Household::factory()->create();
        $list = Checklist::factory()->create(['household_id' => $household->id]);
        $done = ChecklistItem::factory()->done()->create(['checklist_id' => $list->id]);
        $open = ChecklistItem::factory()->create(['checklist_id' => $list->id]);

        Livewire::test('display.lists')->call('clearDone', $list->id);

        $this->assertModelMissing($done);
        $this->assertModelExists($open);
    }
}
