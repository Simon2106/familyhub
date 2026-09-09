<?php

namespace Tests\Feature\Display;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The wall's own settings, moved out of .env and into /admin. */
class WallSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        config([
            'familyhub.dark_mode.start' => '21:00',
            'familyhub.dark_mode.end' => '06:30',
            'familyhub.screensaver.idle_minutes' => 10,
        ]);
    }

    /* ------------------------------ defaults ----------------------------- */

    #[Test]
    public function config_supplies_the_first_answer(): void
    {
        // An installation default is a sensible thing to ship; a wall that can
        // only be re-timed by editing .env is not.
        $this->assertSame(['start' => '21:00', 'end' => '06:30'], $this->household->darkMode());
        $this->assertSame(10, $this->household->screensaverMinutes());
    }

    #[Test]
    public function the_household_overrides_it(): void
    {
        $this->household->setDarkMode('22:15', '07:00');
        $this->household->setScreensaverMinutes(25);

        $fresh = $this->household->fresh();

        $this->assertSame(['start' => '22:15', 'end' => '07:00'], $fresh->darkMode());
        $this->assertSame(25, $fresh->screensaverMinutes());
    }

    #[Test]
    public function a_malformed_time_never_blacks_out_the_wall(): void
    {
        $this->household->setDarkMode('not a time', '');

        $this->assertSame(['start' => '21:00', 'end' => '06:30'], $this->household->fresh()->darkMode());
    }

    #[Test]
    public function zero_minutes_means_never(): void
    {
        $this->household->setScreensaverMinutes(0);

        $this->assertSame(0, $this->household->fresh()->screensaverMinutes());
    }

    #[Test]
    public function an_absurd_delay_is_brought_back_into_range(): void
    {
        $this->household->setScreensaverMinutes(99999);

        $this->assertSame(240, $this->household->fresh()->screensaverMinutes());
    }

    /* ------------------------------- style ------------------------------- */

    #[Test]
    public function an_unset_style_keeps_whatever_the_household_was_already_getting(): void
    {
        // Switching the setting on must not silently take the family's photos
        // away.
        $this->assertSame('clock', $this->household->screensaverStyle());

        Storage::disk('public')->put('photos/beach.jpg', 'not really a jpeg');

        $this->assertSame('photos', $this->household->fresh()->screensaverStyle());
    }

    #[Test]
    public function a_chosen_style_wins_over_the_photographs(): void
    {
        Storage::disk('public')->put('photos/beach.jpg', 'not really a jpeg');

        $this->household->setScreensaverStyle('today');

        $this->assertSame('today', $this->household->fresh()->screensaverStyle());
    }

    #[Test]
    public function a_style_nobody_offers_falls_back_to_the_clock(): void
    {
        $this->household->setScreensaverStyle('interpretive dance');

        $this->assertSame('clock', $this->household->fresh()->screensaverStyle());
    }

    /* ------------------------------- admin ------------------------------- */

    #[Test]
    public function all_four_are_editable_in_settings(): void
    {
        Livewire::test('admin.settings')
            ->assertSee('Dark mode')
            ->assertSee('Screensaver after')
            ->assertSet('darkStart', '21:00')
            ->assertSet('screensaverMinutes', 10)
            ->set('darkStart', '22:30')
            ->set('darkEnd', '05:45')
            ->set('screensaverMinutes', 20)
            ->set('screensaverStyle', 'today')
            ->call('saveHousehold')
            ->assertHasNoErrors();

        $fresh = $this->household->fresh();

        $this->assertSame(['start' => '22:30', 'end' => '05:45'], $fresh->darkMode());
        $this->assertSame(20, $fresh->screensaverMinutes());
        $this->assertSame('today', $fresh->screensaverStyle());
    }

    #[Test]
    public function nonsense_is_refused_rather_than_stored(): void
    {
        Livewire::test('admin.settings')
            ->set('screensaverMinutes', 9999)
            ->call('saveHousehold')
            ->assertHasErrors('screensaverMinutes');

        Livewire::test('admin.settings')
            ->set('darkStart', 'half past nine')
            ->call('saveHousehold')
            ->assertHasErrors('darkStart');
    }

    #[Test]
    public function saving_the_wall_settings_leaves_the_others_alone(): void
    {
        // putSettings reads the blob back before merging; this is the test
        // that catches it if it stops.
        $this->household->setScreenOff(true, '23:15', '07:30');
        $this->household->setDarkMode('22:00', '06:00');

        $fresh = $this->household->fresh();

        $this->assertSame(['enabled' => true, 'start' => '23:15', 'end' => '07:30'], $fresh->screenOff());
        $this->assertSame('22:00', $fresh->darkMode()['start']);
    }

    /* -------------------------------- wall ------------------------------- */

    #[Test]
    public function the_wall_reads_them_rather_than_the_config(): void
    {
        $this->household->setDarkMode('22:45', '05:15');
        $this->household->setScreensaverMinutes(3);
        $this->household->setScreensaverStyle('today');

        Livewire::test('display.wall')
            ->assertSee('22:45', false)
            ->assertSee('05:15', false)
            // Three minutes, in the milliseconds the timer is armed with.
            ->assertSee('180000', false)
            ->assertSee("'today'", false);
    }

    #[Test]
    public function the_page_itself_carries_the_schedule_before_livewire_boots(): void
    {
        // The dark-mode applier is plain DOM and runs whether or not Livewire
        // ever starts, so the attributes have to be right in the HTML.
        config(['familyhub.display.token' => 'wall-token']);
        $this->household->setDarkMode('20:30', '07:45');

        $this->get('/display?token=wall-token')
            ->assertOk()
            ->assertSee('data-dark-start="20:30"', false)
            ->assertSee('data-dark-end="07:45"', false);
    }
}
