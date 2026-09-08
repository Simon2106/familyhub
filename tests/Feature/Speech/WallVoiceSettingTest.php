<?php

namespace Tests\Feature\Speech;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The mute switch, in /admin → Wall display. */
class WallVoiceSettingTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));
    }

    #[Test]
    public function the_wall_speaks_unless_it_is_told_not_to(): void
    {
        // A microphone you have to read the reply from is a strange thing to
        // have installed.
        $this->assertTrue($this->household->wallSpeaks());
    }

    #[Test]
    public function it_can_be_muted_from_the_settings_page(): void
    {
        Livewire::test('admin.settings')
            ->assertSee('Read answers out loud')
            ->assertSet('wallSpeaks', true)
            ->set('wallSpeaks', false)
            ->call('saveHousehold');

        $this->assertFalse($this->household->fresh()->wallSpeaks());
    }

    #[Test]
    public function muting_does_not_disturb_the_rest_of_the_settings(): void
    {
        // putSettings reads the blob back before merging; this is the test
        // that would catch it if it stopped.
        $this->household->setScreenOff(true, '22:30', '07:15');
        $this->household->setWallSpeaks(false);

        $fresh = $this->household->fresh();

        $this->assertFalse($fresh->wallSpeaks());
        $this->assertSame(
            ['enabled' => true, 'start' => '22:30', 'end' => '07:15'],
            $fresh->screenOff(),
        );
    }
}
