<?php

namespace Tests\Feature\Notes;

use App\Models\Household;
use App\Models\Member;
use App\Models\Note;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The fridge door. */
class NotesBoardTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 09:00:00');

        $this->household = Household::factory()->create(['timezone' => 'Europe/London']);
        $this->member = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Jenna', 'colour' => '#e11d48',
        ]);

        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function note(array $attributes = []): Note
    {
        return Note::create($attributes + [
            'household_id' => $this->household->id,
            'body' => 'Back late Tuesday',
        ]);
    }

    #[Test]
    public function a_note_is_written_and_shows_up(): void
    {
        Livewire::test('notes.board')
            ->call('compose')
            ->set('body', 'PE kit in the wash')
            ->set('author', $this->member->id)
            ->call('save')
            ->assertSee('PE kit in the wash')
            // Initials in the corner: a post-it has no room for a full name.
            ->assertSee($this->member->initials());

        $note = Note::first();

        $this->assertSame($this->member->id, $note->member_id);
        // A week by default, because a scrap of paper nobody throws away is
        // exactly the failure this is trying to avoid.
        $this->assertSame('2026-09-17', $note->expires_on->toDateString());
    }

    #[Test]
    public function an_empty_note_is_refused_rather_than_stuck_up_blank(): void
    {
        Livewire::test('notes.board')
            ->call('compose')
            ->set('body', '   ')
            ->call('save')
            ->assertSee('Write something first');

        $this->assertSame(0, Note::count());
    }

    #[Test]
    public function leave_it_up_means_no_expiry_at_all(): void
    {
        Livewire::test('notes.board')
            ->call('compose')
            ->set('body', 'Wifi password is on the router')
            ->set('days', 0)
            ->call('save');

        $this->assertNull(Note::first()->expires_on);
    }

    /** Inclusive: "bins go out Tuesday" is still true on Tuesday. */
    #[Test]
    public function a_note_lasts_through_the_day_it_expires_on(): void
    {
        $this->note(['body' => 'Bins today', 'expires_on' => '2026-09-10']);

        Livewire::test('notes.board')->assertSee('Bins today');
    }

    #[Test]
    public function an_expired_note_is_swept_away_when_the_board_is_read(): void
    {
        $this->note(['body' => 'Last week’s thing', 'expires_on' => '2026-09-09']);
        $this->note(['body' => 'Still current', 'expires_on' => '2026-09-30']);

        Livewire::test('notes.board')
            ->assertDontSee('Last week’s thing')
            ->assertSee('Still current');

        // Swept, not merely hidden: a board read every day should not
        // accumulate a year of dead rows behind it.
        $this->assertSame(['Still current'], Note::pluck('body')->all());
    }

    /**
     * Four fit across; the rest are a finger-swipe away rather than hidden,
     * so the wall no longer has to apologise for what it is not showing.
     */
    #[Test]
    public function the_wall_rails_everything_and_hints_when_it_runs_off_the_edge(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->note(['body' => 'Note number '.$i]);
        }

        Livewire::test('notes.board', ['onWall' => true])
            ->assertSet('overflows', true)
            ->assertSee('Note number 8')
            ->assertDontSee('more on the phone');
    }

    #[Test]
    public function a_board_that_fits_gets_no_edge_hint(): void
    {
        $this->note();
        $this->note(['body' => 'Second']);

        Livewire::test('notes.board', ['onWall' => true])->assertSet('overflows', false);
    }

    #[Test]
    public function the_phone_shows_all_of_them(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->note(['body' => 'Note number '.$i]);
        }

        Livewire::test('notes.board')
            ->assertSee('Note number 7')
            ->assertDontSee('more on the phone');
    }

    #[Test]
    public function a_note_can_be_changed_and_taken_down(): void
    {
        $note = $this->note();

        Livewire::test('notes.board')
            ->call('edit', $note->id)
            ->assertSet('body', 'Back late Tuesday')
            ->set('body', 'Back late Wednesday')
            ->call('save');

        $this->assertSame('Back late Wednesday', $note->fresh()->body);

        Livewire::test('notes.board')->call('remove', $note->id);

        $this->assertSame(0, Note::count());
    }

    #[Test]
    public function another_household_s_note_is_neither_shown_nor_editable(): void
    {
        $theirs = Note::create([
            'household_id' => Household::factory()->create()->id,
            'body' => 'Not ours at all',
        ]);

        Livewire::test('notes.board')
            ->assertDontSee('Not ours at all')
            ->call('remove', $theirs->id);

        $this->assertNotNull($theirs->fresh());
    }

    #[Test]
    public function the_words_under_a_note_say_when_it_goes(): void
    {
        $today = CarbonImmutable::parse('2026-09-10');

        $this->assertSame('today', $this->note(['expires_on' => '2026-09-10'])->until($today));
        $this->assertSame('tomorrow', $this->note(['expires_on' => '2026-09-11'])->until($today));
        $this->assertSame('until Sunday', $this->note(['expires_on' => '2026-09-13'])->until($today));
        $this->assertSame('until 30 Sep', $this->note(['expires_on' => '2026-09-30'])->until($today));
        $this->assertNull($this->note()->until($today));
    }

    #[Test]
    public function a_note_with_nobody_attached_is_everyones(): void
    {
        $this->note();

        Livewire::test('notes.board')->assertSee('Everyone');

        // Classic post-it yellow, rather than somebody's colour.
        $this->assertSame(Note::PLAIN_PAPER, Note::first()->paper());
    }

    /* ----------------------------- the paper --------------------------- */

    /**
     * The author's colour, most of the way into a warm white: enough to say
     * whose it is at a glance, not enough to fight the words on it.
     */
    #[Test]
    public function a_notes_paper_is_its_authors_colour_tinted_right_down(): void
    {
        $note = $this->note(['member_id' => $this->member->id])->fresh();

        $paper = $note->paper();

        $this->assertNotSame(Note::PLAIN_PAPER, $paper);
        $this->assertNotSame($this->member->colour, $paper, 'Tinted, not the raw colour.');

        // Far lighter than the member colour it came from.
        $this->assertGreaterThan(
            hexdec(substr(ltrim($this->member->colour, '#'), 2, 2)),
            hexdec(substr(ltrim($paper, '#'), 2, 2)),
        );
    }

    #[Test]
    public function a_broken_colour_falls_back_to_plain_paper(): void
    {
        $odd = Member::factory()->create([
            'household_id' => $this->household->id, 'name' => 'Odd', 'colour' => 'not-a-colour',
        ]);

        $note = $this->note(['member_id' => $odd->id])->fresh();

        $this->assertSame(Note::PLAIN_PAPER, $note->paper());
    }

    /** Alternating, so a row does not all lean the same way. */
    #[Test]
    public function the_tilt_alternates_and_stays_small(): void
    {
        $note = $this->note();

        foreach ([0, 1, 2, 3] as $position) {
            $degrees = $note->tilt($position);

            $this->assertGreaterThanOrEqual(1, abs($degrees));
            $this->assertLessThanOrEqual(3, abs($degrees));
            $this->assertSame($position % 2 === 0, $degrees < 0, 'Even positions lean left.');
        }
    }

    /** A note keeps its own angle rather than jumping when the board redraws. */
    #[Test]
    public function a_notes_tilt_does_not_change_between_renders(): void
    {
        $note = $this->note();

        $this->assertSame($note->tilt(0), $note->fresh()->tilt(0));
    }

    /* ---------------------------- taking one down ---------------------- */

    /**
     * A long press asks. Reaching past the board and catching a note is easy,
     * and one that vanished under somebody's hand would be gone with nothing
     * to undo it with.
     */
    #[Test]
    public function a_long_press_asks_before_it_takes_a_note_down(): void
    {
        $note = $this->note();

        Livewire::test('notes.board', ['onWall' => true])
            ->call('askToRemove', $note->id)
            ->assertSet('removing', $note->id)
            ->assertSee('Take this note down?');

        $this->assertSame(1, Note::count(), 'Asked, not done.');
    }

    #[Test]
    public function the_confirm_takes_it_down_and_cancel_leaves_it(): void
    {
        $note = $this->note();

        Livewire::test('notes.board', ['onWall' => true])
            ->call('askToRemove', $note->id)
            ->call('cancelRemove')
            ->assertSet('removing', null);

        $this->assertSame(1, Note::count());

        Livewire::test('notes.board', ['onWall' => true])
            ->call('askToRemove', $note->id)
            ->call('remove', $note->id);

        $this->assertSame(0, Note::count());
    }

    #[Test]
    public function another_households_note_cannot_be_long_pressed_away(): void
    {
        $theirs = Note::create([
            'household_id' => Household::factory()->create()->id,
            'body' => 'Not ours at all',
        ]);

        Livewire::test('notes.board', ['onWall' => true])
            ->call('askToRemove', $theirs->id)
            ->assertSet('removing', null);

        $this->assertNotNull($theirs->fresh());
    }

    /* --------------------------- finding it ---------------------------- */

    /**
     * A lone + on a bare strip says nothing about what it makes, and a wall
     * has no hover to explain it with.
     */
    #[Test]
    public function the_empty_wall_board_says_what_the_control_is_for(): void
    {
        Livewire::test('notes.board', ['onWall' => true])
            ->assertSee('Add a note')
            ->assertSee('Gran’s here at 4', false);
    }

    #[Test]
    public function the_empty_phone_board_says_the_same_thing(): void
    {
        Livewire::test('notes.board')
            ->assertSee('Add a note')
            ->assertSee('Gran’s here at 4', false);
    }

    #[Test]
    public function the_add_control_keeps_its_label_once_there_are_notes(): void
    {
        $this->note();

        Livewire::test('notes.board', ['onWall' => true])
            ->assertSee('Add a note')
            // The example is only offered when there is nothing to copy from.
            ->assertDontSee('Gran’s here at 4', false);

        Livewire::test('notes.board')->assertSee('Add a note');
    }

    #[Test]
    public function the_empty_state_is_the_add_control(): void
    {
        Livewire::test('notes.board', ['onWall' => true])
            ->call('compose')
            ->assertSet('composing', true);
    }
}
