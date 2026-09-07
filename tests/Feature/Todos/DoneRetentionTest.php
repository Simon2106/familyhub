<?php

namespace Tests\Feature\Todos;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DoneRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-08 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function doneItem(string $title, string $doneAt): ChecklistItem
    {
        return ChecklistItem::create([
            'checklist_id' => Checklist::home($this->household)->id,
            'title' => $title,
            'is_done' => true,
            'done_at' => CarbonImmutable::parse($doneAt),
        ]);
    }

    #[Test]
    public function retention_defaults_to_thirty_days(): void
    {
        $this->assertSame(30, $this->household->doneRetentionDays());
    }

    #[Test]
    public function retention_can_be_set_per_household(): void
    {
        $this->household->setDoneRetentionDays(7);

        $this->assertSame(7, $this->household->fresh()->doneRetentionDays());
    }

    #[Test]
    public function a_nonsensical_retention_is_floored_at_a_day(): void
    {
        // Zero would delete items the moment they were ticked, which is
        // indistinguishable from losing them.
        $this->household->setDoneRetentionDays(0);

        $this->assertSame(1, $this->household->fresh()->doneRetentionDays());
    }

    #[Test]
    public function pruning_removes_only_items_past_the_window(): void
    {
        $old = $this->doneItem('Long done', '2026-06-01 10:00');
        $recent = $this->doneItem('Just done', '2026-07-07 10:00');

        $this->artisan('familyhub:prune-done')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    #[Test]
    public function pruning_leaves_open_items_alone(): void
    {
        $open = ChecklistItem::create([
            'checklist_id' => Checklist::home($this->household)->id,
            'title' => 'Still to do',
            'created_at' => CarbonImmutable::parse('2026-01-01'),
        ]);

        $this->artisan('familyhub:prune-done')->assertSuccessful();

        $this->assertModelExists($open);
    }

    #[Test]
    public function pruning_respects_a_custom_window(): void
    {
        $this->household->setDoneRetentionDays(3);

        $item = $this->doneItem('Done last week', '2026-07-01 10:00');

        $this->artisan('familyhub:prune-done')->assertSuccessful();

        $this->assertModelMissing($item);
    }

    #[Test]
    public function an_item_with_no_completion_time_is_not_guessed_at(): void
    {
        $item = ChecklistItem::create([
            'checklist_id' => Checklist::home($this->household)->id,
            'title' => 'Ticked before we recorded when',
            'is_done' => true,
            'done_at' => null,
        ]);

        $this->artisan('familyhub:prune-done')->assertSuccessful();

        $this->assertModelExists($item);
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $old = $this->doneItem('Long done', '2026-06-01 10:00');

        $this->artisan('familyhub:prune-done', ['--dry-run' => true])
            ->expectsOutputToContain('1 completed items are older than 30 days')
            ->assertSuccessful();

        $this->assertModelExists($old);
    }

    #[Test]
    public function the_lists_tab_offers_clear_done(): void
    {
        $this->doneItem('Long done', '2026-07-07 10:00');

        Livewire::test('display.lists')
            ->assertSee('Clear done')
            ->assertSee('Cleared automatically after 30 days.');
    }

    #[Test]
    public function clear_done_empties_the_done_section_now(): void
    {
        $done = $this->doneItem('Just done', '2026-07-07 10:00');
        $open = ChecklistItem::create([
            'checklist_id' => Checklist::home($this->household)->id,
            'title' => 'Still to do',
        ]);

        Livewire::test('display.lists')->call('clearDone', Checklist::home($this->household)->id);

        $this->assertModelMissing($done);
        $this->assertModelExists($open);
    }

    #[Test]
    public function retention_is_editable_in_admin(): void
    {
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Livewire::test('admin.settings')
            ->assertSet('doneRetentionDays', 30)
            ->set('doneRetentionDays', 14)
            ->call('saveHousehold')
            ->assertHasNoErrors();

        $this->assertSame(14, $this->household->fresh()->doneRetentionDays());
    }

    #[Test]
    public function admin_rejects_an_impossible_retention(): void
    {
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Livewire::test('admin.settings')
            ->set('doneRetentionDays', 0)
            ->call('saveHousehold')
            ->assertHasErrors('doneRetentionDays');
    }

    #[Test]
    public function the_prune_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e) => $e->command.' @ '.$e->expression);

        $this->assertTrue(
            $events->contains(fn (string $e) => str_contains($e, 'familyhub:prune-done') && str_contains($e, '0 4 * * *')),
            'Expected a daily prune; got: '.$events->implode(', '),
        );
    }
}
