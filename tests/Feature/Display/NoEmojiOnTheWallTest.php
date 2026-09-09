<?php

namespace Tests\Feature\Display;

use App\Models\BinCollection;
use App\Services\Weather\Forecast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wall draws its own glyphs.
 *
 * The Pi kiosk has no emoji font, so an emoji on /display is an empty box —
 * and every device that does have one draws them differently, which had the
 * same forecast looking like three different forecasts on a wall, an iPad and
 * a phone.
 */
class NoEmojiOnTheWallTest extends TestCase
{
    /** Everything the wall renders, plus the shared pieces it renders through. */
    protected const VIEWS = [
        'display/⚡wall.blade.php',
        'weather-tile.blade.php',
        'ha-icon.blade.php',
        'home/⚡panel.blade.php',
        'meals/⚡plan.blade.php',
        'meals/⚡tonight.blade.php',
        'recipes/⚡box.blade.php',
        'kids/⚡my-day.blade.php',
        'kids/⚡ledger.blade.php',
        'search/⚡box.blade.php',
        'assistant/⚡ask.blade.php',
    ];

    /**
     * Pictographs and dingbats — the ranges a text font may simply not have.
     *
     * The icon component itself is exempt: it is where the drawings live.
     */
    protected const EMOJI = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';

    public static function views(): array
    {
        return array_map(fn (string $view) => [$view], self::VIEWS);
    }

    #[Test]
    #[DataProvider('views')]
    public function nothing_the_wall_draws_needs_an_emoji_font(string $view): void
    {
        $path = __DIR__.'/../../../resources/views/components/'.$view;

        $this->assertFileExists($path, 'The list of wall views has gone stale.');

        $markup = file_get_contents($path);

        // Placeholders are hints about the family's own data, not something
        // the wall draws.
        $markup = preg_replace('/placeholder="[^"]*"/u', '', $markup) ?? $markup;

        $this->assertDoesNotMatchRegularExpression(
            self::EMOJI,
            $markup,
            "{$view} still has an emoji in it — the kiosk has no font for it. Use <x-icon>.",
        );
    }

    #[Test]
    public function the_forecast_names_a_drawing_rather_than_a_glyph(): void
    {
        foreach ([0, 2, 3, 45, 55, 65, 75, 80, 85, 95] as $code) {
            $name = (new Forecast(temperature: 12.0, code: $code))->icon();

            $this->assertDoesNotMatchRegularExpression(self::EMOJI, $name);
            $this->assertMatchesRegularExpression('/^[a-z-]+$/', $name, "Code {$code} gave {$name}");
        }
    }

    #[Test]
    public function every_name_the_app_asks_for_is_one_the_icon_set_draws(): void
    {
        // A name with no drawing behind it falls through to a plain circle,
        // which is a bug that looks like a design decision.
        $icons = file_get_contents(__DIR__.'/../../../resources/views/components/icon.blade.php');

        preg_match_all("/^\s*'([a-z-]+)' =>/m", $icons, $defined);

        $wanted = ['sun', 'moon', 'cloud', 'sun-cloud', 'fog', 'drizzle', 'rain', 'snow',
            'snow-showers', 'storm', 'light', 'switch', 'climate', 'cover', 'scene', 'script',
            'device', 'music', 'thumb-up', 'thumb-down', 'thumbs-split', 'celebrate', 'gift',
            'ticked', 'unticked', 'check', 'cutlery', 'star', 'undo', 'pencil',
            'bin', 'recycle', 'box', 'leaf', 'apple', 'plug'];

        foreach ($wanted as $name) {
            $this->assertContains($name, $defined[1], "<x-icon name=\"{$name}\"> has nothing to draw.");
        }
    }

    #[Test]
    public function a_bin_names_a_drawing_too(): void
    {
        foreach (BinCollection::KINDS as $kind => $bin) {
            $this->assertDoesNotMatchRegularExpression(self::EMOJI, $bin['icon'], "The {$kind} bin.");
        }
    }
}
