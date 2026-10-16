<?php

namespace Tests\Feature\Display;

use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every tap target on the Home face, and something that answers it.
 *
 * The rebuild shipped three buttons wired to events nothing listened for —
 * Add a note, the to-do +, and Announce — and dropped the member column's
 * handler entirely. Each looked fine in the markup. These check the other
 * half: that whatever a control fires, something is listening.
 */
class HomeFaceTapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['familyhub.display.token' => 'wall-token']);

        $household = Household::factory()->create();
        User::factory()->create(['household_id' => $household->id]);

        Member::factory()->create(['household_id' => $household->id, 'name' => 'Ada', 'is_child' => true]);
        Member::factory()->create(['household_id' => $household->id, 'name' => 'Bo']);
    }

    protected function wall(): string
    {
        return $this->withoutVite()->get('/display?token=wall-token')->assertOk()->getContent();
    }

    #[Test]
    public function a_childs_column_opens_their_day(): void
    {
        $html = $this->wall();

        $this->assertStringContainsString('data-opens-day', $html);
        $this->assertStringContainsString('show-my-day', $html);

        // Routed client-side, so opening a dialog does not redraw three
        // weeks of calendar on the way.
        $this->assertStringContainsString('window.Livewire.dispatch(\'show-my-day\'', $html);
        $this->assertStringNotContainsString('$wire.dispatch(\'show-my-day\'', $html);
    }

    #[Test]
    public function an_adults_column_is_not_a_button(): void
    {
        // As it was before the rebuild: my-day is a child's screen.
        $html = $this->wall();

        preg_match_all('/data-opens-day="(\d+)"/', $html, $found);

        $children = Member::where('is_child', true)->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->assertSame($children, array_values(array_unique($found[1])));
    }

    #[Test]
    public function every_event_a_control_fires_has_something_listening(): void
    {
        // The rebuild's actual bug: invented event names, no listeners.
        $face = file_get_contents(__DIR__.'/../../../resources/views/components/wall/face.blade.php');

        preg_match_all("/dispatch\('([a-z-]+)'/", $face, $found);

        $app = __DIR__.'/../../../resources/views/components';

        foreach (array_unique($found[1]) as $event) {
            $listeners = shell_exec(
                'grep -rl '.escapeshellarg("On('{$event}')").' '.escapeshellarg($app).' 2>/dev/null; '
                .'grep -rl '.escapeshellarg("x-on:{$event}").' '.escapeshellarg($app).' 2>/dev/null'
            );

            $this->assertNotEmpty(
                trim((string) $listeners),
                "The face fires '{$event}' and nothing listens for it.",
            );
        }
    }

    #[Test]
    public function the_notes_board_and_to_do_panel_are_the_real_ones(): void
    {
        // Not a drawing of them. The rebuild's dashed box and static list
        // had no dialog behind either.
        $html = $this->wall();

        $this->assertStringContainsString('wire:name="notes.board"', $html);
        $this->assertStringContainsString('wire:name="todos.panel"', $html);
    }

    #[Test]
    public function a_day_pill_knows_which_day_it_is(): void
    {
        // It read $day['date'] off a list, which is always null, so every
        // pill called pickDay('') and the week strip did nothing.
        $html = $this->wall();

        $this->assertMatchesRegularExpression(
            '/pickDay\(["\']?\\\\?u?0?0?2?2?\d{4}-\d{2}-\d{2}/',
            str_replace('&quot;', '"', $html),
            'Day pills must be given a real date.',
        );
    }
}
