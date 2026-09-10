<?php

namespace Tests\Feature\Summary;

use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Chore;
use App\Models\Event;
use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\User;
use App\Services\Chores\ChoreBoard;
use App\Services\Notifications\NoticeTriggers;
use App\Services\Points\PointsLedger;
use App\Services\Summary\WeeklySummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Sunday-evening look back.
 *
 * Everything is derived on the way past, so the tests that matter are about
 * what counts and what does not: a chore un-ticked, a rating given last month,
 * a dinner that was planned but never happened.
 */
class WeeklySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $child;

    /** Sunday evening, which is the whole point of the feature. */
    protected const SUNDAY = '2026-09-13 18:05:00';

    /** The same instant as the household sees it — the triggers work in local time. */
    protected function sundayEvening(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::SUNDAY, $this->household->displayTimezone());
    }

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::SUNDAY);

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->child = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Joey', 'is_child' => true,
        ]);

        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function summary(): WeeklySummary
    {
        return app(WeeklySummary::class);
    }

    protected function chore(string $title = 'Bins'): Chore
    {
        return Chore::factory()->create([
            'household_id' => $this->household->id,
            'member_id' => $this->child->id,
            'title' => $title,
            'points' => 5,
            'needs_approval' => false,
        ]);
    }

    #[Test]
    public function it_counts_the_jobs_and_the_stars_they_earned(): void
    {
        $board = app(ChoreBoard::class);

        $board->complete($this->chore('Bins'), CarbonImmutable::parse('2026-09-08'));
        $board->complete($this->chore('Table'), CarbonImmutable::parse('2026-09-10'));

        $week = $this->summary()->for($this->household);
        $child = $week->children->first();

        $this->assertSame(2, $child->choresDone);
        $this->assertSame(10, $child->earned);
        $this->assertSame(10, $child->balance);
        $this->assertSame('2 jobs · 10 earned', $child->sentence());
    }

    /** A job taken back is a job that did not happen. */
    #[Test]
    public function an_unticked_chore_stops_counting(): void
    {
        $board = app(ChoreBoard::class);
        $instance = $board->complete($this->chore(), CarbonImmutable::parse('2026-09-08'));

        $board->uncomplete($instance);

        $child = $this->summary()->for($this->household)->children->first();

        $this->assertSame(0, $child->choresDone);
        $this->assertSame(0, $child->earned);
        $this->assertTrue($child->didNothing());
    }

    #[Test]
    public function what_a_child_spent_is_shown_as_what_it_cost(): void
    {
        $board = app(ChoreBoard::class);
        $board->complete($this->chore(), CarbonImmutable::parse('2026-09-08'));

        app(PointsLedger::class)->spend($this->child, $this->child, 3, 'Screen time');

        $child = $this->summary()->for($this->household)->children->first();

        // Stored as -3; a family says "spent 3".
        $this->assertSame(3, $child->spent);
        $this->assertSame(2, $child->balance);
        $this->assertStringContainsString('3 spent', $child->sentence());
    }

    #[Test]
    public function a_child_who_did_nothing_is_still_listed(): void
    {
        $week = $this->summary()->for($this->household);

        $this->assertCount(1, $week->children);
        $this->assertSame('Nothing this week', $week->children->first()->sentence());
    }

    #[Test]
    public function it_reports_the_dinners_and_what_was_said_about_them(): void
    {
        $recipe = Recipe::factory()->create([
            'household_id' => $this->household->id, 'title' => 'Fish pie', 'status' => 'ready',
        ]);

        Meal::create([
            'household_id' => $this->household->id,
            'on' => '2026-09-09', 'slot' => 'dinner', 'title' => 'Fish pie', 'recipe_id' => $recipe->id,
        ]);

        RecipeRating::factory()->create([
            'recipe_id' => $recipe->id, 'member_id' => $this->child->id,
            'stars' => 4, 'thumbs' => 1, 'created_at' => '2026-09-09 19:00:00',
        ]);

        $meal = $this->summary()->for($this->household)->meals->first();

        $this->assertSame('Fish pie', $meal->title);
        $this->assertSame(4.0, $meal->stars);
        $this->assertSame('4/5, 1 liked it', $meal->verdict());
    }

    /** Last month's opinion of the fish pie is not this week's news. */
    #[Test]
    public function a_rating_from_another_week_is_not_counted(): void
    {
        $recipe = Recipe::factory()->create([
            'household_id' => $this->household->id, 'title' => 'Fish pie', 'status' => 'ready',
        ]);

        Meal::create([
            'household_id' => $this->household->id,
            'on' => '2026-09-09', 'slot' => 'dinner', 'title' => 'Fish pie', 'recipe_id' => $recipe->id,
        ]);

        RecipeRating::factory()->create([
            'recipe_id' => $recipe->id, 'member_id' => $this->child->id,
            'stars' => 5, 'created_at' => '2026-08-01 19:00:00',
        ]);

        $meal = $this->summary()->for($this->household)->meals->first();

        $this->assertNull($meal->stars);
        $this->assertFalse($meal->rated());
    }

    #[Test]
    public function next_week_is_one_line_forwards_not_a_second_calendar(): void
    {
        $calendar = Calendar::factory()->create([
            'calendar_account_id' => CalendarAccount::factory()->create(['household_id' => $this->household->id])->id,
            'is_visible' => true,
        ]);

        for ($i = 0; $i < 9; $i++) {
            Event::factory()->for($calendar)->create([
                'title' => 'Thing '.$i,
                'start_at' => CarbonImmutable::parse('2026-09-14 09:00:00')->addDays($i % 5)->addHours($i),
                'end_at' => CarbonImmutable::parse('2026-09-14 10:00:00')->addDays($i % 5)->addHours($i),
            ]);
        }

        $ahead = $this->summary()->for($this->household)->ahead;

        $this->assertSame('2026-09-14', $ahead->weekStart->toDateString());
        $this->assertSame(9, $ahead->eventCount);
        $this->assertCount(4, $ahead->highlights);
        $this->assertSame('No dinners planned yet', $ahead->mealSentence());
    }

    /* ---------------------------- the notification -------------------- */

    #[Test]
    public function it_is_due_on_a_sunday_evening_and_not_otherwise(): void
    {
        $this->assertTrue($this->summary()->isDue($this->household, CarbonImmutable::parse('2026-09-13 18:30')));
        $this->assertFalse($this->summary()->isDue($this->household, CarbonImmutable::parse('2026-09-13 17:59')));
        $this->assertFalse($this->summary()->isDue($this->household, CarbonImmutable::parse('2026-09-13 19:00')));
        $this->assertFalse($this->summary()->isDue($this->household, CarbonImmutable::parse('2026-09-14 18:30')));
    }

    #[Test]
    public function the_trigger_fires_once_on_a_sunday_with_the_week_in_it(): void
    {
        app(ChoreBoard::class)->complete($this->chore(), CarbonImmutable::parse('2026-09-08'));

        $user = User::factory()->create(['household_id' => $this->household->id]);

        $notices = app(NoticeTriggers::class)
            ->forUser($user, $this->household, $this->sundayEvening())
            ->filter(fn ($n) => $n->trigger === 'weekly_summary');

        $this->assertCount(1, $notices);

        // The subject names the week, so the every-minute schedule cannot send
        // it sixty times.
        $this->assertSame('week:2026-09-07', $notices->first()->subject);
        $this->assertStringContainsString('1 jobs done', $notices->first()->body);
    }

    /** A week nothing happened in is not news. */
    #[Test]
    public function an_empty_week_is_never_announced(): void
    {
        $user = User::factory()->create(['household_id' => $this->household->id]);

        $notices = app(NoticeTriggers::class)
            ->forUser($user, $this->household, $this->sundayEvening())
            ->filter(fn ($n) => $n->trigger === 'weekly_summary');

        $this->assertCount(0, $notices);
    }

    /* ------------------------------- the screen ----------------------- */

    #[Test]
    public function the_screen_shows_the_week_on_one_page(): void
    {
        app(ChoreBoard::class)->complete($this->chore('Bins'), CarbonImmutable::parse('2026-09-08'));

        Meal::create([
            'household_id' => $this->household->id,
            'on' => '2026-09-09', 'slot' => 'dinner', 'title' => 'Fish pie',
        ]);

        Livewire::test('summary.week')
            ->assertSee('Joey')
            ->assertSee('1 jobs')
            ->assertSee('Fish pie')
            ->assertSee('Coming up');
    }

    #[Test]
    public function it_can_be_walked_back_and_never_forward_past_this_week(): void
    {
        Meal::create([
            'household_id' => $this->household->id,
            'on' => '2026-09-02', 'slot' => 'dinner', 'title' => 'Last week’s chilli',
        ]);

        Livewire::test('summary.week')
            ->assertDontSee('Last week’s chilli')
            ->call('shift', 1)
            ->assertSee('Last week’s chilli')
            ->call('shift', -1)
            ->call('shift', -1)
            ->assertSet('weeksBack', 0);
    }
}
