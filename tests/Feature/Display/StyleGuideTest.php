<?php

namespace Tests\Feature\Display;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wall's design language, and the page that shows it.
 *
 * The style guide lives on the wall rather than in a document because glass
 * over a photograph cannot be judged from a swatch, and 17px cannot be
 * judged from a laptop eighteen inches away.
 */
class StyleGuideTest extends TestCase
{
    use RefreshDatabase;

    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        config(['familyhub.display.token' => 'wall-token']);

        $this->household = Household::factory()->create();
        User::factory()->create(['household_id' => $this->household->id]);
    }

    protected function guide(): string
    {
        return $this->withoutVite()->get('/display/style?token=wall-token')->assertOk()->getContent();
    }

    #[Test]
    public function the_style_guide_is_behind_the_display_token(): void
    {
        // Everything on the wall is, and a page that is not is a page
        // somebody can find from outside the house.
        // The same refusal the wall itself gives an unpaired visitor.
        $this->get('/display/style')->assertForbidden();

        $this->withoutVite()->get('/display/style?token=wall-token')->assertOk();
    }

    #[Test]
    public function it_shows_every_step_of_the_type_scale(): void
    {
        $html = $this->guide();

        foreach (['t-clock', 't-face', 't-title', 't-body', 't-label'] as $role) {
            $this->assertStringContainsString($role, $html, "The scale is missing {$role}.");
        }

        // And says what each one is, in pixels, because that is the thing
        // being checked against a panel.
        foreach (['160px', '48px', '28px', '22px', '17px'] as $size) {
            $this->assertStringContainsString($size, $html);
        }
    }

    #[Test]
    public function it_puts_glass_on_the_page_to_be_looked_at(): void
    {
        $html = $this->guide();

        $this->assertStringContainsString('class="glass', $html);
        // The refraction map the edges bend through.
        $this->assertStringContainsString('glass-refraction', $html);
    }

    #[Test]
    public function the_style_guide_links_nowhere(): void
    {
        // It is on the kiosk, and the kiosk has no way back.
        preg_match_all('/<a\b[^>]*\bhref=("|\')(.*?)\1/i', $this->guide(), $found);

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

    /**
     * Nothing on the wall falls through to the blank circle.
     *
     * The source scan in NoEmojiOnTheWallTest only sees a literal name. The
     * tab bar passes its icons through a variable, so `label` — a name with
     * no drawing behind it, taken from the component's own prop list rather
     * than from the icon set — sailed past it and shipped an empty circle on
     * the Review tab. This looks at what is actually painted.
     */
    #[Test]
    public function nothing_on_the_wall_falls_through_to_the_blank_circle(): void
    {
        foreach (['/display?token=wall-token', '/display/style?token=wall-token'] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                '<circle cx="12" cy="12" r="8.5"/>',
                $html,
                "{$url} is drawing the unknown-icon fallback, which looks like a design decision.",
            );
        }
    }

    /* --------------------------- lighter glass -------------------------- */

    #[Test]
    public function the_lighter_glass_setting_drops_the_expensive_layer(): void
    {
        // The displacement ring is the only part of this that costs a Pi
        // anything, so it is the first thing a struggling wall gives up.
        $this->assertStringContainsString('glass-refraction', $this->guide());
        $this->assertStringNotContainsString('lite-glass', $this->guide());

        $this->household->setLiteGlass(true);

        Once::flush();

        $html = $this->guide();

        $this->assertStringContainsString('lite-glass', $html);
        $this->assertStringNotContainsString('glass-refraction', $html, 'The filter is not even rendered.');
    }

    #[Test]
    public function the_wall_itself_honours_the_lighter_glass_setting(): void
    {
        $this->household->setLiteGlass(true);

        Once::flush();

        $this->assertStringContainsString(
            'lite-glass',
            $this->withoutVite()->get('/display?token=wall-token')->getContent(),
        );
    }
}
