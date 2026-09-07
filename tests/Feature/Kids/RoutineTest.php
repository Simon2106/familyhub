<?php

namespace Tests\Feature\Kids;

use App\Models\Household;
use App\Models\Member;
use App\Models\Routine;
use App\Models\RoutineStepCompletion;
use App\Models\User;
use App\Services\Routines\RoutineBoard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Routines: the window they run in, the ticking, and the fuss at the end. */
class RoutineTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $joey;

    protected function setUp(): void
    {
        parent::setUp();

        // 07:30 on a Wednesday: inside a 07:00–08:30 morning routine.
        CarbonImmutable::setTestNow('2026-09-09 06:30:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        $this->joey = Member::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Joey',
            'is_child' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function routine(array $attributes = [], array $steps = ['Teeth', 'Shoes']): Routine
    {
        $routine = Routine::factory()->create($attributes + [
            'household_id' => $this->household->id,
            'member_id' => $this->joey->id,
        ]);

        foreach ($steps as $index => $title) {
            $routine->steps()->create(['title' => $title, 'sort_order' => $index]);
        }

        return $routine->fresh();
    }

    protected function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-09 '.$time, 'Europe/London');
    }

    #[Test]
    public function a_routine_is_running_only_inside_its_window(): void
    {
        $routine = $this->routine(['starts_at' => '07:00:00', 'ends_at' => '08:30:00']);

        $this->assertFalse($routine->isActiveAt($this->at('06:59')));
        $this->assertTrue($routine->isActiveAt($this->at('07:00')));
        $this->assertTrue($routine->isActiveAt($this->at('08:29')));
        $this->assertFalse($routine->isActiveAt($this->at('08:30')), 'The end of the window is exclusive.');
    }

    #[Test]
    public function a_window_that_crosses_midnight_still_works(): void
    {
        // A bedtime routine running late is a real thing to want.
        $routine = $this->routine(['starts_at' => '20:00:00', 'ends_at' => '00:30:00']);

        $this->assertTrue($routine->isActiveAt($this->at('20:00')));
        $this->assertTrue($routine->isActiveAt($this->at('23:59')));
        $this->assertTrue($routine->isActiveAt($this->at('00:15')));
        $this->assertFalse($routine->isActiveAt($this->at('19:59')));
        $this->assertFalse($routine->isActiveAt($this->at('00:30')));
    }

    #[Test]
    public function a_paused_routine_is_never_running(): void
    {
        $routine = $this->routine(['is_active' => false]);

        $this->assertFalse($routine->isActiveAt($this->at('07:30')));
    }

    #[Test]
    public function looking_at_a_routine_writes_nothing(): void
    {
        $this->routine();

        app(RoutineBoard::class)->forMember($this->joey, CarbonImmutable::parse('2026-09-09'));

        $this->assertDatabaseCount('routine_step_completions', 0);
    }

    #[Test]
    public function ticking_a_step_records_it_for_that_day_only(): void
    {
        $routine = $this->routine();
        $step = $routine->steps->first();
        $board = app(RoutineBoard::class);

        $board->complete($step, CarbonImmutable::parse('2026-09-09'));

        $this->assertTrue($board->isComplete($step, CarbonImmutable::parse('2026-09-09')));
        $this->assertFalse($board->isComplete($step, CarbonImmutable::parse('2026-09-10')),
            'A routine resets simply by the date changing.');
    }

    #[Test]
    public function ticking_the_same_step_twice_records_it_once(): void
    {
        $routine = $this->routine();
        $step = $routine->steps->first();
        $board = app(RoutineBoard::class);

        $board->complete($step, CarbonImmutable::parse('2026-09-09'));
        $board->complete($step, CarbonImmutable::parse('2026-09-09'));

        $this->assertSame(1, RoutineStepCompletion::count());
    }

    #[Test]
    public function progress_counts_up_to_all_done(): void
    {
        $routine = $this->routine();
        $board = app(RoutineBoard::class);
        $date = CarbonImmutable::parse('2026-09-09');

        $progress = $board->forMember($this->joey, $date)->first();
        $this->assertSame('0/2', $progress->summary());
        $this->assertFalse($progress->allDone());

        foreach ($routine->steps as $step) {
            $board->complete($step, $date);
        }

        $progress = $board->forMember($this->joey, $date)->first();
        $this->assertSame('2/2', $progress->summary());
        $this->assertTrue($progress->allDone());
    }

    #[Test]
    public function a_routine_with_no_steps_is_not_all_done(): void
    {
        $this->routine(steps: []);

        $progress = app(RoutineBoard::class)->forMember($this->joey, CarbonImmutable::parse('2026-09-09'))->first();

        $this->assertFalse($progress->allDone(), 'An empty routine has not been completed, it is unfinished setup.');
    }

    #[Test]
    public function only_the_routine_in_its_window_counts_as_running(): void
    {
        $this->routine(['name' => 'Morning', 'starts_at' => '07:00:00', 'ends_at' => '08:30:00']);
        $this->routine(['name' => 'Bedtime', 'kind' => 'bedtime', 'starts_at' => '19:00:00', 'ends_at' => '20:30:00']);

        $active = app(RoutineBoard::class)->activeFor($this->joey, CarbonImmutable::parse('2026-09-09'), $this->at('07:30'));

        $this->assertNotNull($active);
        $this->assertSame('Morning', $active->routine->name);
    }

    #[Test]
    public function yesterdays_routine_is_never_running_today(): void
    {
        $this->routine();

        $active = app(RoutineBoard::class)->activeFor(
            $this->joey,
            CarbonImmutable::parse('2026-09-08'),
            $this->at('07:30'),
        );

        $this->assertNull($active, '"Right now" only means anything on the day it is now.');
    }

    #[Test]
    public function the_childs_view_shows_the_running_routine_first(): void
    {
        $this->routine(['name' => 'Bedtime', 'kind' => 'bedtime', 'starts_at' => '19:00:00', 'ends_at' => '20:30:00'], ['Pyjamas']);
        $this->routine(['name' => 'Morning', 'starts_at' => '07:00:00', 'ends_at' => '08:30:00'], ['Teeth']);

        $names = Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->instance()->routines->pluck('routine.name')->all();

        $this->assertSame(['Morning', 'Bedtime'], $names);
    }

    #[Test]
    public function ticking_a_step_from_the_childs_view_never_asks_for_a_pin(): void
    {
        $routine = $this->routine();
        $step = $routine->steps->first();

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->call('tickStep', $step->id)
            ->assertNotDispatched('need-pin');

        $this->assertSame(1, RoutineStepCompletion::count());
    }

    #[Test]
    public function finishing_a_routine_is_made_a_fuss_of(): void
    {
        $routine = $this->routine(['name' => 'Morning'], ['Teeth']);

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->assertDontSee('Morning all done!')
            ->call('tickStep', $routine->steps->first()->id)
            ->assertSee('Morning all done!');
    }

    #[Test]
    public function a_step_belonging_to_another_child_cannot_be_ticked(): void
    {
        $sienna = Member::factory()->create(['household_id' => $this->household->id, 'is_child' => true]);
        $theirs = Routine::factory()->create(['household_id' => $this->household->id, 'member_id' => $sienna->id]);
        $step = $theirs->steps()->create(['title' => 'Not yours']);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test('kids.my-day')
            ->call('show', $this->joey->id, '2026-09-09')
            ->call('tickStep', $step->id);
    }

    #[Test]
    public function the_wall_shows_a_chip_while_a_routine_is_running(): void
    {
        $this->routine(['name' => 'Morning', 'starts_at' => '00:00:00', 'ends_at' => '23:59:00']);

        Livewire::test('display.wall')->assertSee('Morning 0/2');
    }

    #[Test]
    public function the_wall_leaves_a_routine_alone_outside_its_window(): void
    {
        $this->routine(['name' => 'Bedtime', 'starts_at' => '23:00:00', 'ends_at' => '23:30:00']);

        Livewire::test('display.wall')->assertDontSee('Bedtime 0/2');
    }
}
