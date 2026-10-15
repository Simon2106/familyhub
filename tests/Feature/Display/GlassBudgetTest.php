<?php

namespace Tests\Feature\Display;

use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Glass is for panels, and there are not many panels.
 *
 * This guard exists because the first sweep put .glass on every recipe card,
 * every photograph and every review item — eighty-one sheets of glass on the
 * Home face, each with its own backdrop filter, at twenty frames a second.
 * A blur inside a blur is two passes over the same pixels and the Pi cannot
 * afford one of them.
 */
class GlassBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** What the design brief allows on screen at once. */
    public const BUDGET = 6;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        config(['familyhub.display.token' => 'wall-token']);

        $this->household = Household::factory()->create();
        User::factory()->create(['household_id' => $this->household->id]);
    }

    /** Every `class="... glass ..."` in the markup, panels only. */
    protected function panelsIn(string $html): int
    {
        preg_match_all('/class="([^"]*)"/', $html, $found);

        return count(array_filter(
            $found[1],
            fn (string $classes) => in_array('glass', preg_split('/\s+/', trim($classes)) ?: [], true),
        ));
    }

    #[Test]
    public function the_wall_renders_panels_rather_than_a_pane_per_row(): void
    {
        // Four members, so the faces have columns and the grid has rows —
        // the shape that produced eighty-one.
        foreach (['Ada', 'Bo', 'Cy', 'Di'] as $name) {
            Member::factory()->create(['household_id' => $this->household->id, 'name' => $name]);
        }

        $html = $this->withoutVite()->get('/display?token=wall-token')->assertOk()->getContent();

        $panels = $this->panelsIn($html);

        // Generous against the on-screen budget of six, because this counts
        // every tab's panels including the ones behind an x-show. What it
        // catches is the order-of-magnitude mistake: a panel per list row.
        $this->assertLessThanOrEqual(
            self::BUDGET * 4,
            $panels,
            "The wall is rendering {$panels} sheets of glass. Glass is for panels; rows want .tile.",
        );
    }

    #[Test]
    public function a_list_row_is_a_tile_rather_than_a_sheet_of_glass(): void
    {
        $views = [
            'photos/⚡page.blade.php',
            'recipes/⚡box.blade.php',
            'capture/⚡review.blade.php',
        ];

        foreach ($views as $view) {
            $source = file_get_contents(__DIR__.'/../../../resources/views/components/'.$view);

            $this->assertStringContainsString(
                'tile',
                $source,
                "{$view} draws a list of things and should be drawing them as tiles.",
            );
        }
    }

    #[Test]
    public function the_wall_watches_its_own_frame_rate(): void
    {
        // Nobody is going to stand in a kitchen with a profiler, so the rule
        // the brief set out — below fifty, drop the ring, then the
        // saturation — is applied by the wall itself.
        $app = file_get_contents(__DIR__.'/../../../resources/js/app.js');

        $this->assertStringContainsString('watchOnce', $app);
        $this->assertStringContainsString('data-kiosk', $app);
    }
}
