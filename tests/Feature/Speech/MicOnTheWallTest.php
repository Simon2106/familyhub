<?php

namespace Tests\Feature\Speech;

use App\Models\Household;
use App\Models\User;
use App\Services\Speech\Contracts\SpeechToText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSpeechToText;
use Tests\TestCase;

/** The microphone as it appears on the wall. */
class MicOnTheWallTest extends TestCase
{
    use RefreshDatabase;

    protected FakeSpeechToText $ears;

    protected function setUp(): void
    {
        parent::setUp();

        Household::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->ears = new FakeSpeechToText;
        $this->app->instance(SpeechToText::class, $this->ears);
    }

    #[Test]
    public function the_wall_offers_a_microphone(): void
    {
        Livewire::test('display.wall')
            ->assertSee('wallMic', false)
            ->assertSee('Ask a question', false);
    }

    #[Test]
    public function with_no_key_there_is_no_button_to_be_disappointed_by(): void
    {
        // A kiosk with no keyboard is the worst possible place to discover a
        // missing setting.
        $this->ears->configured = false;

        Livewire::test('display.wall')
            ->assertDontSee('wallMic', false)
            ->assertDontSee('Ask a question', false);
    }

    #[Test]
    public function the_dialog_says_it_can_only_look_things_up(): void
    {
        Livewire::test('display.wall')->assertSee('it never changes anything', false);
    }

    #[Test]
    public function the_dialog_is_the_one_the_rest_of_the_app_uses(): void
    {
        // ModalDismissalTest keeps everything else honest; this is the wall's
        // own check that the new dialog went through <x-modal>.
        Livewire::test('display.wall')->assertSee('modal-backdrop modal-viewport', false);
    }

    #[Test]
    public function the_wall_is_told_where_to_post_and_where_to_listen(): void
    {
        // Compared as they are rendered: @js escapes the slashes, so a plain
        // '/display/listen' would never match however right the route is.
        $asRendered = fn (string $url) => trim(json_encode($url), '"');

        Livewire::test('display.wall')
            ->assertSee($asRendered(route('display.listen')), false)
            // The id is stripped back off, leaving a prefix the browser
            // appends the real one to.
            ->assertSee($asRendered(Str::beforeLast(
                route('display.speech', ['id' => 'x']), '/x'
            )), false);
    }
}
