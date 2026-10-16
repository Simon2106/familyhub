<?php

namespace Tests\Feature\Display;

use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Display\WallFace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wall changes face through the day.
 *
 * A kitchen asks different questions at different hours, and a screen that
 * shows the same grid for all of them is a screen that becomes wallpaper.
 */
class WallFaceTest extends TestCase
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

    protected function faceAt(string $when): string
    {
        return app(WallFace::class)->at(
            $this->household,
            CarbonImmutable::parse($when, $this->household->displayTimezone()),
        );
    }

    #[Test]
    public function the_hour_decides_which_face_the_wall_wears(): void
    {
        $this->assertSame(WallFace::DAY, $this->faceAt('2026-10-14 06:30'));
        $this->assertSame(WallFace::DAY, $this->faceAt('2026-10-14 09:00'));
        $this->assertSame(WallFace::DAY, $this->faceAt('2026-10-14 16:59'));
        $this->assertSame(WallFace::EVENING, $this->faceAt('2026-10-14 17:00'));
        $this->assertSame(WallFace::NIGHT, $this->faceAt('2026-10-14 21:00'));
    }

    #[Test]
    public function the_small_hours_are_still_last_night(): void
    {
        // Before the first boundary of the day there is no boundary passed
        // at all, and the honest answer is that it is still night.
        $this->assertSame(WallFace::NIGHT, $this->faceAt('2026-10-14 02:30'));
        $this->assertSame(WallFace::NIGHT, $this->faceAt('2026-10-14 06:29'));
    }

    #[Test]
    public function there_is_no_morning_face_any_more(): void
    {
        // There was one — a sparser board of routines and what had to go in
        // a bag. The household wanted the full day grid from the moment they
        // came downstairs, which is what the day face already is.
        $this->assertSame([WallFace::DAY, WallFace::EVENING, WallFace::NIGHT], WallFace::ORDER);
        $this->assertArrayNotHasKey('morning', WallFace::LABELS);
        $this->assertSame('06:30', WallFace::DEFAULTS[WallFace::DAY]);
    }

    #[Test]
    public function the_weekend_is_no_different_from_a_weekday(): void
    {
        // It was, while there was a morning face to keep off a Saturday.
        $this->assertSame(WallFace::DAY, $this->faceAt('2026-10-17 07:00'));
        $this->assertSame(WallFace::DAY, $this->faceAt('2026-10-14 07:00'));
    }

    #[Test]
    public function the_boundaries_can_be_moved(): void
    {
        $this->household->setFaceTimes([
            'day' => '10:00',
            'evening' => '18:30',
            'night' => '22:00',
        ]);

        Once::flush();

        $this->assertSame(WallFace::NIGHT, $this->faceAt('2026-10-14 09:59'));
        $this->assertSame(WallFace::DAY, $this->faceAt('2026-10-14 10:00'));
        $this->assertSame(WallFace::EVENING, $this->faceAt('2026-10-14 18:30'));
        $this->assertSame(WallFace::NIGHT, $this->faceAt('2026-10-14 22:00'));
    }

    #[Test]
    public function rubbish_in_the_settings_falls_back_rather_than_breaking_the_wall(): void
    {
        $this->household->setFaceTimes(['day' => '25:00', 'evening' => '', 'night' => '21:30']);

        Once::flush();

        $times = $this->household->fresh()->faceTimes();

        $this->assertSame('06:30', $times['day']);
        $this->assertSame('17:00', $times['evening']);
        $this->assertSame('21:30', $times['night'], 'A good one is still kept.');
    }

    #[Test]
    public function the_browser_is_handed_the_boundaries_as_minutes(): void
    {
        // Sent as minutes past midnight so the wall can decide for itself
        // every minute rather than waiting for the next poll.
        $this->assertSame(
            ['day' => 390, 'evening' => 1020, 'night' => 1260],
            app(WallFace::class)->boundaries($this->household),
        );
    }

    /* -------------------------- what it renders ------------------------- */

    #[Test]
    public function the_wall_sends_the_boundaries_down_with_everything_else(): void
    {
        $html = $this->withoutVite()->get('/display?token=wall-token')->assertOk()->getContent();

        $this->assertStringContainsString('faceBounds', $html);
        $this->assertStringContainsString('naturalFace', $html);
        // Every face has somewhere to render.
        $this->assertStringContainsString("face !== 'evening'", $html);
        $this->assertStringContainsString("face === 'evening'", $html);
        $this->assertStringContainsString("face === 'night'", $html);
    }

    #[Test]
    public function the_day_columns_show_what_a_child_needs_in_a_bag(): void
    {
        // This was the morning face's one genuinely morning-shaped thing.
        // The face is gone; the chips moved rather than being lost.
        $child = Member::factory()->create([
            'household_id' => $this->household->id,
            'name' => 'Ellie',
            'is_child' => true,
        ]);

        $child->setSchoolNotes(['wed' => 'PE kit, recorder']);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 07:30', $this->household->displayTimezone()));

        $html = $this->withoutVite()->get('/display?token=wall-token')->assertOk()->getContent();

        $this->assertStringContainsString('PE kit', $html);
        $this->assertStringContainsString('recorder', $html);
    }

    #[Test]
    public function a_note_for_another_day_stays_on_that_day(): void
    {
        $child = Member::factory()->create(['household_id' => $this->household->id, 'is_child' => true]);

        $child->setSchoolNotes(['tue' => 'Swimming kit']);

        // A Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 07:30', $this->household->displayTimezone()));

        $this->assertStringNotContainsString(
            'Swimming kit',
            $this->withoutVite()->get('/display?token=wall-token')->getContent(),
        );
    }

    #[Test]
    public function the_night_face_is_dimmed_by_however_much_admin_said(): void
    {
        $this->household->setNightDim(0.7);

        Once::flush();

        $html = $this->withoutVite()->get('/display?token=wall-token')->getContent();

        $this->assertStringContainsString('nightDim', $html);
        $this->assertStringContainsString('0.7', $html);
    }

    /**
     * There must always be a way off the night face.
     *
     * It is a full-screen layer over the header, so the face switcher is
     * underneath it. Choosing Night from that switcher at nine in the
     * morning stranded the wall until five in the afternoon — the override
     * only cleared when the natural face changed, and no tap could reach
     * anything. The same class of bug as the /admin link that needed an
     * SSH session to undo.
     */
    #[Test]
    public function a_tap_always_gets_you_off_the_night_face(): void
    {
        $wall = file_get_contents(
            __DIR__.'/../../../resources/views/components/display/⚡wall.blade.php'
        );

        // A touch suppresses the night face for a while...
        $this->assertStringContainsString('recentlyWoken', $wall);
        $this->assertStringContainsString('wokeAt', $wall);

        // ...and a night face chosen by hand is given up entirely.
        $this->assertMatchesRegularExpression(
            "/wake\(\)\s*\{.*?faceOverride === 'night'.*?faceOverride = null/s",
            $wall,
            'wake() must clear a manual night override.',
        );

        // And nightly must actually consult it, or none of that matters.
        $this->assertMatchesRegularExpression(
            '/get nightly\(\)\s*\{.*?!this\.recentlyWoken/s',
            $wall,
        );
    }

    #[Test]
    public function the_dimmer_refuses_to_black_the_wall_out_entirely(): void
    {
        // Fully black with a clock nobody can read is not a face, it is a
        // fault somebody will unplug the wall over.
        $this->household->setNightDim(5.0);

        $this->assertSame(0.9, $this->household->fresh()->nightDim());
    }
}
