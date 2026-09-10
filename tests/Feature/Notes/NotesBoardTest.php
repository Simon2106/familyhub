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
            ->assertSee('Jenna');

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

    #[Test]
    public function the_wall_shows_six_and_says_how_many_it_is_not_showing(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->note(['body' => 'Note number '.$i]);
        }

        Livewire::test('notes.board', ['onWall' => true])
            ->assertSee('Note number 6')
            ->assertDontSee('Note number 7')
            ->assertSee('and 2 more on the phone');
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

        $this->assertSame('#64748b', Note::first()->colour());
    }
}
