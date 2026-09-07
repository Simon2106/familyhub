<?php

namespace Tests\Feature\Kids;

use App\Models\Chore;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Setting a chore up, and the day picker in particular. */
class ChoreAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $joey;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-09 07:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_picked_day_shows_as_picked(): void
    {
        // A checkbox hands back strings, and the tiles compare strictly
        // against ints. Without normalising, days updates but the tile never
        // lights up — so the picker looks broken while working perfectly.
        $component = Livewire::test('admin.chores')
            ->call('addChore')
            ->set('recurrence', 'days')
            ->set('days', ['2', '4']);

        $this->assertSame([2, 4], $component->get('days'));
    }

    #[Test]
    public function picked_days_are_stored_as_numbers(): void
    {
        Livewire::test('admin.chores')
            ->call('addChore')
            ->set('title', 'Bins')
            ->set('memberId', (string) $this->joey->id)
            ->set('recurrence', 'days')
            ->set('days', ['4', '2'])
            ->call('save')
            ->assertHasNoErrors();

        $chore = Chore::first();

        $this->assertSame([2, 4], $chore->days, 'Stored in week order, as numbers.');
        $this->assertSame([2, 4], $chore->weekdays());
        $this->assertTrue($chore->occursOn(CarbonImmutable::parse('2026-09-10')));
        $this->assertFalse($chore->occursOn(CarbonImmutable::parse('2026-09-11')));
    }

    #[Test]
    public function the_same_day_picked_twice_is_still_one_day(): void
    {
        $component = Livewire::test('admin.chores')
            ->call('addChore')
            ->set('recurrence', 'days')
            ->set('days', ['2', '2']);

        $this->assertSame([2], $component->get('days'));
    }

    #[Test]
    public function a_weekly_chore_takes_exactly_one_day(): void
    {
        Livewire::test('admin.chores')
            ->call('addChore')
            ->set('title', 'Bins')
            ->set('memberId', (string) $this->joey->id)
            ->set('recurrence', 'weekly')
            ->set('weeklyDay', '4')
            ->call('save')
            ->assertHasNoErrors();

        $chore = Chore::first();

        $this->assertSame([4], $chore->days);
        $this->assertSame('Every Thu', $chore->scheduleLabel());
    }

    #[Test]
    public function editing_a_chore_shows_the_days_it_already_has(): void
    {
        $chore = Chore::factory()->create([
            'household_id' => $this->household->id,
            'member_id' => $this->joey->id,
            'recurrence' => 'days',
            'days' => [2, 5],
        ]);

        $component = Livewire::test('admin.chores')->call('edit', $chore->id);

        $this->assertSame([2, 5], $component->get('days'));
    }

    #[Test]
    public function editing_a_weekly_chore_shows_its_day(): void
    {
        $chore = Chore::factory()->create([
            'household_id' => $this->household->id,
            'member_id' => $this->joey->id,
            'recurrence' => 'weekly',
            'days' => [4],
        ]);

        $component = Livewire::test('admin.chores')->call('edit', $chore->id);

        $this->assertSame('4', $component->get('weeklyDay'));
    }

    #[Test]
    public function picking_no_days_at_all_is_refused_rather_than_saved_as_never(): void
    {
        // Silently saving a chore that falls due on nothing is worse than
        // saying so: it looks set up and never appears.
        Livewire::test('admin.chores')
            ->call('addChore')
            ->set('title', 'Bins')
            ->set('memberId', (string) $this->joey->id)
            ->set('recurrence', 'days')
            ->set('days', [])
            ->call('save')
            ->assertHasErrors('days');

        $this->assertSame(0, Chore::count());
    }

    #[Test]
    public function everyday_and_school_days_need_no_days_picked(): void
    {
        foreach (['daily', 'weekdays'] as $recurrence) {
            Livewire::test('admin.chores')
                ->call('addChore')
                ->set('title', 'Feed the cat')
                ->set('memberId', (string) $this->joey->id)
                ->set('recurrence', $recurrence)
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, Chore::count());
        $this->assertNull(Chore::first()->days);
    }
}
