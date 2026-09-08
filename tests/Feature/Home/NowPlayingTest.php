<?php

namespace Tests\Feature\Home;

use App\Models\Household;
use App\Models\User;
use App\Services\HomeAssistant\MediaPlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeHomeAssistant;
use Tests\TestCase;

/**
 * What is playing in the house, on the Switches tab.
 *
 * Found rather than configured: a speaker nobody has added to the wall still
 * has to be able to say what is coming out of it.
 */
class NowPlayingTest extends TestCase
{
    use RefreshDatabase;

    /** Everything a Sonos-shaped device advertises. */
    protected const FULL = MediaPlayer::PAUSE | MediaPlayer::PLAY | MediaPlayer::PREVIOUS
        | MediaPlayer::NEXT | MediaPlayer::VOLUME_STEP | MediaPlayer::VOLUME_SET;

    protected function setUp(): void
    {
        parent::setUp();

        $household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $household->id]));

        FakeHomeAssistant::fake();
    }

    protected function tearDown(): void
    {
        FakeHomeAssistant::reset();

        parent::tearDown();
    }

    protected function player(string $state, array $attributes = [], string $id = 'media_player.kitchen'): void
    {
        FakeHomeAssistant::entity($id, $state, $attributes + [
            'friendly_name' => 'Kitchen speaker',
            'supported_features' => self::FULL,
        ]);
    }

    #[Test]
    public function what_is_playing_is_shown_with_who_it_is_by(): void
    {
        $this->player('playing', ['media_title' => 'Blackbird', 'media_artist' => 'The Beatles']);

        Livewire::test('home.panel')
            ->assertSee('Blackbird')
            ->assertSee('The Beatles')
            ->assertSee('Playing on')
            ->assertSee('Kitchen speaker');
    }

    #[Test]
    public function a_paused_player_says_so_rather_than_disappearing(): void
    {
        // Paused is the state you most want the buttons for.
        $this->player('paused', ['media_title' => 'Blackbird']);

        Livewire::test('home.panel')->assertSee('Paused on');
    }

    #[Test]
    public function a_device_with_no_track_name_is_not_named_twice(): void
    {
        // A radio reports a channel and no title, so the heading is already
        // the device — "Playing on Bathroom radio" underneath says nothing
        // twice.
        FakeHomeAssistant::entity('media_player.radio', 'playing', [
            'friendly_name' => 'Bathroom radio', 'media_channel' => 'BBC Radio 4',
        ]);

        Livewire::test('home.panel')
            ->assertSee('Bathroom radio')
            ->assertSee('BBC Radio 4')
            ->assertDontSee('Playing on Bathroom radio');
    }

    #[Test]
    public function a_silent_speaker_is_not_mentioned_at_all(): void
    {
        $this->player('idle', ['friendly_name' => 'Kitchen speaker']);
        FakeHomeAssistant::entity('media_player.lounge', 'off', ['friendly_name' => 'Lounge telly']);

        Livewire::test('home.panel')
            ->assertDontSee('Kitchen speaker')
            ->assertDontSee('Lounge telly');
    }

    #[Test]
    public function a_speaker_needs_no_tile_to_be_heard_from(): void
    {
        // Nothing has been added to the wall; the card still appears.
        $this->player('playing', ['media_title' => 'Blackbird']);

        Livewire::test('home.panel')
            ->assertSee('Blackbird')
            ->assertSee('Nothing on the wall yet');
    }

    #[Test]
    public function each_playing_thing_gets_its_own_card(): void
    {
        $this->player('playing', ['media_title' => 'Blackbird']);
        $this->player('playing', ['friendly_name' => 'Lounge telly', 'media_title' => 'The News'], 'media_player.lounge');

        Livewire::test('home.panel')
            ->assertSee('Blackbird')
            ->assertSee('The News');
    }

    /* ------------------------------ controls ----------------------------- */

    #[Test]
    public function play_pause_asks_home_assistant_to_play_pause(): void
    {
        $this->player('playing', ['media_title' => 'Blackbird']);

        Livewire::test('home.panel')->call('control', 'media_player.kitchen', 'play-pause');

        $this->assertSame(
            '/api/services/media_player/media_play_pause',
            FakeHomeAssistant::serviceCalls()[0]['path'],
        );
        $this->assertSame('media_player.kitchen', FakeHomeAssistant::serviceCalls()[0]['body']['entity_id']);
    }

    #[Test]
    public function skipping_and_the_volume_map_to_the_right_services(): void
    {
        $this->player('playing');

        $component = Livewire::test('home.panel');

        foreach ([
            'next' => 'media_next_track',
            'previous' => 'media_previous_track',
            'louder' => 'volume_up',
            'quieter' => 'volume_down',
        ] as $action => $service) {
            $component->call('control', 'media_player.kitchen', $action);
        }

        $this->assertSame(
            ['media_next_track', 'media_previous_track', 'volume_up', 'volume_down'],
            array_map(
                fn (array $call) => basename($call['path']),
                FakeHomeAssistant::serviceCalls(),
            ),
        );
    }

    #[Test]
    public function a_device_that_cannot_skip_is_not_offered_a_skip_button(): void
    {
        // A radio has no next track, and a button that does nothing teaches
        // the household not to trust the wall's buttons.
        FakeHomeAssistant::entity('media_player.radio', 'playing', [
            'friendly_name' => 'Kitchen radio',
            'media_title' => 'Today',
            'supported_features' => MediaPlayer::PAUSE,
        ]);

        Livewire::test('home.panel')
            ->assertSee('Today')
            ->assertSee('Pause')
            ->assertDontSee('Next track')
            ->assertDontSee('Louder');
    }

    #[Test]
    public function anything_that_is_not_a_media_player_is_refused(): void
    {
        // The wall is a screen anybody can walk up to; "call any service on
        // any entity" is not something it needs to be able to do.
        FakeHomeAssistant::entity('light.kitchen', 'on', ['friendly_name' => 'Kitchen spots']);
        $this->player('playing');

        Livewire::test('home.panel')
            ->call('control', 'light.kitchen', 'play-pause')
            ->assertSee('not a media player');

        $this->assertSame([], FakeHomeAssistant::serviceCalls());
    }

    #[Test]
    public function an_action_nobody_offered_is_refused(): void
    {
        $this->player('playing');

        Livewire::test('home.panel')
            ->call('control', 'media_player.kitchen', 'set_volume_to_maximum')
            ->assertSee('Unknown media action');

        $this->assertSame([], FakeHomeAssistant::serviceCalls());
    }

    /* ------------------------------ the model ---------------------------- */

    #[Test]
    public function artwork_is_made_absolute_against_home_assistant(): void
    {
        $player = MediaPlayer::fromArray([
            'entity_id' => 'media_player.kitchen',
            'state' => 'playing',
            'attributes' => ['entity_picture' => '/api/media_player_proxy/media_player.kitchen?token=abc'],
        ]);

        $this->assertSame(
            FakeHomeAssistant::URL.'/api/media_player_proxy/media_player.kitchen?token=abc',
            $player->artwork(),
        );

        // Already absolute, and nothing to say at all.
        $this->assertSame('https://art/x.jpg', MediaPlayer::fromArray([
            'entity_id' => 'media_player.kitchen', 'state' => 'playing',
            'attributes' => ['entity_picture' => 'https://art/x.jpg'],
        ])->artwork());

        $this->assertNull(MediaPlayer::fromArray([
            'entity_id' => 'media_player.kitchen', 'state' => 'playing',
        ])->artwork());
    }

    #[Test]
    public function the_subtitle_falls_back_through_what_the_device_knows(): void
    {
        $subtitle = fn (array $attributes) => MediaPlayer::fromArray([
            'entity_id' => 'media_player.x', 'state' => 'playing', 'attributes' => $attributes,
        ])->subtitle();

        $this->assertSame('The Beatles', $subtitle(['media_artist' => 'The Beatles', 'app_name' => 'Spotify']));
        $this->assertSame('Bluey', $subtitle(['media_series_title' => 'Bluey']));
        $this->assertSame('BBC Radio 4', $subtitle(['media_channel' => 'BBC Radio 4']));
        $this->assertSame('Spotify', $subtitle(['app_name' => 'Spotify']));
        $this->assertNull($subtitle([]));
        // Whitespace is not a subtitle.
        $this->assertNull($subtitle(['media_artist' => '   ']));
    }
}
