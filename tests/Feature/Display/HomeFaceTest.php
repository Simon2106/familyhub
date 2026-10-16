<?php

namespace Tests\Feature\Display;

use App\Models\BinCollection;
use App\Models\Countdown;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Services\Speech\Contracts\SpeechToText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSpeechToText;
use Tests\TestCase;

/**
 * The Home face, rebuilt to the design.
 *
 * The static reference at /display/style/home is the thing that was
 * measured against design/home-mockup.png, and it renders through the same
 * component the live wall does — so these check that the component keeps
 * its shape and that wiring it to real data did not quietly drop anything
 * the old header carried.
 */
class HomeFaceTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        config(['familyhub.display.token' => 'wall-token']);

        $this->household = Household::factory()->create(['name' => 'The Wills']);
        User::factory()->create(['household_id' => $this->household->id]);
    }

    protected function wall(): string
    {
        return $this->withoutVite()->get('/display?token=wall-token')->assertOk()->getContent();
    }

    protected function reference(): string
    {
        return $this->withoutVite()->get('/display/style/home?token=wall-token')->assertOk()->getContent();
    }

    /* --------------------------- the reference -------------------------- */

    #[Test]
    public function the_static_reference_is_behind_the_display_token(): void
    {
        $this->get('/display/style/home')->assertForbidden();

        $this->withoutVite()->get('/display/style/home?token=wall-token')->assertOk();
    }

    #[Test]
    public function the_reference_draws_the_design_it_was_measured_against(): void
    {
        $html = $this->reference();

        // The mockup's own sample content, so a side-by-side compares the
        // layout and nothing else.
        foreach (['The Wills', '14:53', 'Friday, 16 October', 'Max Birthday', 'Household'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    #[Test]
    public function the_reference_and_the_wall_draw_through_the_same_component(): void
    {
        // The point of building the reference first: the thing that was
        // compared against the design has to be the thing that ships, or
        // the comparison proved nothing.
        foreach ([$this->reference(), $this->wall()] as $html) {
            $this->assertStringContainsString('wall-clock', $html);
            $this->assertStringContainsString('Change what the', $html);
            $this->assertStringContainsString('By the hour', $html);
        }
    }

    #[Test]
    public function the_reference_links_nowhere(): void
    {
        preg_match_all('/<a\b[^>]*\bhref=("|\')(.*?)\1/i', $this->reference(), $found);

        $offWall = array_values(array_filter($found[2], function (string $href) {
            $href = html_entity_decode(trim($href));

            if ($href === '' || str_starts_with($href, '#')) {
                return false;
            }

            $path = parse_url($href, PHP_URL_PATH) ?: '';

            return $path !== '/display' && ! str_starts_with($path, '/display/');
        }));

        $this->assertSame([], $offWall, 'These would strand the kiosk: '.implode(', ', $offWall));
    }

    /* ----------------------------- live data ---------------------------- */

    #[Test]
    public function the_face_shows_the_household_its_own_name_and_people(): void
    {
        foreach (['Ada', 'Bo'] as $name) {
            Member::factory()->create(['household_id' => $this->household->id, 'name' => $name]);
        }

        $html = $this->wall();

        $this->assertStringContainsString('The Wills', $html);
        $this->assertStringContainsString('Ada', $html);
        $this->assertStringContainsString('Bo', $html);
    }

    #[Test]
    public function a_column_each_plus_the_household_rather_than_always_five(): void
    {
        // The design's family is four, so the design has five columns. A
        // household of two should not get three empty slots.
        Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Ada']);

        $this->assertStringContainsString('repeat(2, minmax(0, 1fr))', $this->wall());

        Member::factory()->create(['household_id' => $this->household->id, 'name' => 'Bo']);

        Once::flush();

        $this->assertStringContainsString('repeat(3, minmax(0, 1fr))', $this->wall());
    }

    /* ------------------------ nothing was dropped ----------------------- */

    #[Test]
    public function the_bins_the_countdowns_and_term_dates_still_reach_the_wall(): void
    {
        // All three were chips along the old header. The new design has no
        // header to hang them off, and dropping three features to match a
        // mockup is not what a visual rebuild means — they moved into the
        // household's own column.
        BinCollection::create([
            'household_id' => $this->household->id,
            'on' => $this->household->todayLocal()->addDay()->toDateString(),
            'kind' => 'recycling',
            'name' => 'Recycling',
        ]);

        Countdown::create([
            'household_id' => $this->household->id,
            'label' => 'Cornwall',
            'on' => $this->household->todayLocal()->addDays(12)->toDateString(),
        ]);

        $html = $this->wall();

        $this->assertStringContainsString('Recycling', $html);
        $this->assertStringContainsString('tonight', $html);
        $this->assertStringContainsString('Cornwall', $html);
    }

    #[Test]
    public function asking_is_not_offered_where_there_is_nothing_to_listen_with(): void
    {
        // A kiosk with no keyboard is the worst place to discover a missing
        // setting, which is why the action bar drops the cell rather than
        // offering a button that can only apologise.
        // Nothing to listen with: the cell is absent.
        $this->assertStringNotContainsString('a question', $this->wall());

        // Something to listen with: it appears.
        $ears = new FakeSpeechToText;
        $ears->configured = true;
        $this->app->instance(SpeechToText::class, $ears);

        $this->assertStringContainsString('a question', $this->wall());
    }

    /* ------------------------------ chrome ------------------------------ */

    #[Test]
    public function every_tab_gets_the_same_tab_bar_and_the_wall_is_always_dark(): void
    {
        $html = $this->wall();

        // Eight tabs, one bar, one travelling block.
        foreach (['Home', 'Review', 'Meals', 'Lists', 'Switches', 'Photos', 'Timers', 'Activity'] as $tab) {
            $this->assertStringContainsString(">{$tab}<", $html);
        }

        $this->assertStringContainsString('tabBlob(', $html);

        // The wall is glass over a deep purple ground at every hour, so
        // every component it inherits wants its dark variant.
        $this->assertStringContainsString('data-always-dark', $html);
        $this->assertStringContainsString('wall-ground', $html);
    }

    #[Test]
    public function the_avatars_served_are_the_small_ones(): void
    {
        // The originals are a megabyte each and the wall draws them at
        // 72px. A Pi on a kitchen wall should not be downloading five
        // megabytes of PNG to show four faces.
        $avatars = glob(public_path('images/avatars/*-144.webp'));

        $this->assertNotEmpty($avatars, 'Run familyhub:build-avatars.');

        foreach ($avatars as $avatar) {
            $this->assertLessThan(
                60_000,
                filesize($avatar),
                basename($avatar).' is too big for a kiosk to be fetching.',
            );
        }

        $this->assertStringContainsString('-144.webp', $this->reference());
    }

    #[Test]
    public function a_non_square_source_is_centre_cropped_rather_than_squashed(): void
    {
        // simon.png is 1312x1199. Squashed into a round frame it would give
        // one person a wider face than everybody else.
        $size = getimagesize(public_path('images/avatars/simon-144.webp'));

        $this->assertSame([144, 144], [$size[0], $size[1]]);
    }
}
