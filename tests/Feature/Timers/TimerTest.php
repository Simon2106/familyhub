<?php

namespace Tests\Feature\Timers;

use App\Models\Household;
use App\Models\Timer;
use App\Models\User;
use App\Services\Timers\TimerBoard;
use App\Services\Timers\TimerParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Timers that outlive the page showing them.
 *
 * The whole point of putting these in a table is that a Chromium which
 * restarts at ten past six comes back to a pasta timer with four minutes on
 * it. Everything below is about that promise.
 */
class TimerTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        User::factory()->create(['household_id' => $this->household->id]);

        config(['familyhub.display.token' => 'wall-token']);
    }

    protected function board(): TimerBoard
    {
        return app(TimerBoard::class);
    }

    /* ------------------------------ the row ----------------------------- */

    #[Test]
    public function a_timer_is_an_end_time_rather_than_a_countdown(): void
    {
        // Nothing decrements it, so nothing has to be running for it to be
        // right — which is what lets it survive a restart.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00:00'));

        $timer = $this->board()->start($this->household, 20 * 60, 'Pasta');

        $this->assertSame(1200, $timer->remaining());

        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:16:00'));

        $this->assertSame(240, $timer->fresh()->remaining());
        $this->assertFalse($timer->fresh()->hasFinished());

        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:21:00'));

        $this->assertSame(0, $timer->fresh()->remaining());
        $this->assertTrue($timer->fresh()->hasFinished());
    }

    #[Test]
    public function a_finished_timer_never_counts_backwards(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00:00'));

        $timer = $this->board()->start($this->household, 60);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 20:00:00'));

        $this->assertSame(0, $timer->fresh()->remaining(), 'Minus two hours is not a thing a kitchen timer says.');
    }

    #[Test]
    public function pausing_holds_what_was_left_and_resuming_hands_it_back(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00:00'));

        $timer = $this->board()->start($this->household, 600, 'Bread');

        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:04:00'));

        $timer->pause();

        $this->assertTrue($timer->isPaused());
        $this->assertSame(360, $timer->remaining());

        // An hour on the counter changes nothing: that is what paused means.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:04:00'));

        $this->assertSame(360, $timer->fresh()->remaining());

        $timer->fresh()->resume();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:05:00'));

        $this->assertSame(300, $timer->fresh()->remaining());
    }

    #[Test]
    public function a_dismissed_timer_leaves_the_strip(): void
    {
        $timer = $this->board()->start($this->household, 600);

        $this->assertCount(1, $this->board()->live($this->household));

        $timer->dismiss();

        $this->assertCount(0, $this->board()->live($this->household));
    }

    #[Test]
    public function a_wall_and_a_phone_do_not_both_chime_for_the_same_pasta(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00:00'));

        $this->board()->start($this->household, 60, 'Pasta');

        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:02:00'));

        // Whichever screen notices first says so; the second one is told it
        // is already handled.
        $this->assertCount(1, $this->board()->ring($this->household));
        $this->assertCount(0, $this->board()->ring($this->household));
    }

    #[Test]
    public function nobody_sets_a_kitchen_timer_for_a_week(): void
    {
        $timer = $this->board()->start($this->household, 999_999, 'Forever');

        $this->assertSame(Timer::MAX_SECONDS, $timer->seconds);
    }

    /* ------------------------------ hearing ----------------------------- */

    protected function parser(): TimerParser
    {
        return app(TimerParser::class);
    }

    #[Test]
    public function it_hears_a_timer_in_a_sentence(): void
    {
        $request = $this->parser()->parse('set a 20 minute pasta timer');

        $this->assertSame(1200, $request->seconds);
        $this->assertSame('Pasta', $request->name);
        $this->assertFalse($request->guessed);
    }

    #[Test]
    public function it_hears_one_that_does_not_use_the_word_timer(): void
    {
        $request = $this->parser()->parse('10 minutes until we leave');

        $this->assertSame(600, $request->seconds);
        $this->assertSame('We leave', $request->name);
    }

    #[Test]
    public function spoken_numbers_count_too(): void
    {
        // Whisper writes "twenty" as often as it writes 20.
        $this->assertSame(1200, $this->parser()->seconds('set a twenty minute timer'));
        $this->assertSame(1800, $this->parser()->seconds('half an hour timer'));
        $this->assertSame(5400, $this->parser()->seconds('an hour and a half timer'));
        $this->assertSame(4800, $this->parser()->seconds('set a 1 hour 20 minute timer'));
    }

    #[Test]
    public function an_unclear_duration_gets_five_minutes_and_an_apology(): void
    {
        // A timer silently set to the wrong length is worse than no timer,
        // so the wall says what it did.
        $request = $this->parser()->parse('set a timer for the chips');

        $this->assertSame(Timer::FALLBACK_SECONDS, $request->seconds);
        $this->assertTrue($request->guessed);
        $this->assertStringContainsString("didn't catch how long", $request->confirmation());
        $this->assertStringContainsString('5 minutes', $request->confirmation());
    }

    #[Test]
    public function a_question_about_timers_is_not_a_request_for_one(): void
    {
        // Answering a question with a timer is a far worse failure than
        // setting no timer, so anything that might be one goes to the
        // assistant instead.
        foreach ([
            'how long left on the pasta timer',
            'how much longer',
            'what timers are running',
            'cancel the pasta timer',
        ] as $said) {
            $this->assertFalse($this->parser()->looksLikeOne($said), $said);
        }

        foreach ([
            'set a 20 minute pasta timer',
            '10 minutes until we leave',
            'remind me in 5 minutes',
        ] as $said) {
            $this->assertTrue($this->parser()->looksLikeOne($said), $said);
        }
    }

    #[Test]
    public function an_ordinary_question_is_left_well_alone(): void
    {
        foreach ([
            "what's for tea",
            'when is the dentist',
            'who is picking up Ellie',
        ] as $said) {
            $this->assertFalse($this->parser()->looksLikeOne($said), $said);
        }
    }

    /* ----------------------------- the strip ---------------------------- */

    #[Test]
    public function the_strip_shows_what_is_running_and_can_stop_it(): void
    {
        $timer = $this->board()->start($this->household, 600, 'Pasta');

        Livewire::test('display.timers')
            ->assertSee('Pasta')
            ->call('dismiss', $timer->id);

        $this->assertNotNull($timer->fresh()->dismissed_at);
    }

    #[Test]
    public function the_strip_pauses_and_resumes_the_same_button(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00:00'));

        $timer = $this->board()->start($this->household, 600);

        $strip = Livewire::test('display.timers')->call('pause', $timer->id);

        $this->assertTrue($timer->fresh()->isPaused());

        $strip->call('pause', $timer->id);

        $this->assertFalse($timer->fresh()->isPaused());
    }

    #[Test]
    public function cook_mode_asks_the_strip_for_a_timer_rather_than_keeping_one(): void
    {
        // A timer set at the hob used to vanish the moment somebody tapped
        // Done, and said nothing at all from any other tab.
        Livewire::test('display.timers')->call('startFor', 20, 'Chilli');

        $timer = Timer::first();

        $this->assertNotNull($timer);
        $this->assertSame('Chilli', $timer->name);
        $this->assertSame(1200, $timer->seconds);
        $this->assertSame('cook', $timer->source);
    }

    #[Test]
    public function one_can_be_set_by_hand_from_the_timers_tab(): void
    {
        Livewire::test('display.timer-board')
            ->set('name', 'Laundry')
            ->set('minutes', 45)
            ->call('start')
            ->assertHasNoErrors();

        $this->assertSame(45 * 60, Timer::first()->seconds);
        $this->assertSame('Laundry', Timer::first()->name);
    }

    #[Test]
    public function a_timer_belongs_to_its_own_household(): void
    {
        $other = Household::factory()->create();

        $this->board()->start($other, 600, 'Someone else\'s');

        $this->assertCount(0, $this->board()->live($this->household));
    }

    #[Test]
    public function the_strip_rides_above_every_tab_on_the_wall(): void
    {
        $this->board()->start($this->household, 600, 'Pasta');

        $html = $this->withoutVite()->get('/display?token=wall-token')->assertOk()->getContent();

        // Rendered outside the tab panels, so it cannot be on one of them.
        $this->assertStringContainsString('data-timer-strip', $html);
        $this->assertStringContainsString('timerStrip(', $html);
    }

    #[Test]
    public function the_wall_still_links_nowhere_with_a_timer_running(): void
    {
        // The kiosk has no back button; a new panel is a new chance to
        // strand it.
        $this->board()->start($this->household, 600, 'Pasta');

        $html = $this->withoutVite()->get('/display?token=wall-token')->getContent();

        preg_match_all('/<a\b[^>]*\bhref=("|\')(.*?)\1/i', $html, $found);

        $offWall = array_values(array_filter($found[2], function (string $href) {
            $href = html_entity_decode(trim($href));

            if ($href === '' || str_starts_with($href, '#')) {
                return false;
            }

            $path = parse_url($href, PHP_URL_PATH) ?: '';

            return $path !== '/display' && ! str_starts_with($path, '/display/');
        }));

        // Asserted as a list rather than in a loop: a wall that happened to
        // render no anchors at all would make a loop of assertions vacuous,
        // and a vacuous guard is worse than none.
        $this->assertSame([], $offWall, 'These would strand the kiosk: '.implode(', ', $offWall));
    }
}
