<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * There is one dialog in this app, and everything uses it.
 *
 * Two bugs came out of hand-rolling them, and both were invisible until
 * somebody tried to use the thing on a device:
 *
 *   The dim and the centring have to be the *same element*. As two, the
 *   full-screen centring layer covers the backdrop and "tap outside to close"
 *   silently does nothing.
 *
 *   The dialog has to be measured against the **visual** viewport. On iOS the
 *   keyboard shrinks that and leaves the layout viewport at full height, so a
 *   form positioned against the latter puts its Save button behind the
 *   keyboard — which is exactly what the event editor did.
 *
 * `<x-modal>` gets both right once. These keep everything else using it.
 */
class ModalDismissalTest extends TestCase
{
    protected const COMPONENT = __DIR__.'/../../resources/views/components/modal.blade.php';

    /** @return list<array{0: string}> */
    public static function views(): array
    {
        $files = array_merge(
            glob(__DIR__.'/../../resources/views/components/*.blade.php') ?: [],
            glob(__DIR__.'/../../resources/views/components/**/*.blade.php') ?: [],
            glob(__DIR__.'/../../resources/views/*.blade.php') ?: [],
        );

        return array_values(array_map(
            fn (string $file) => [$file],
            array_filter($files, fn (string $file) => realpath($file) !== realpath(self::COMPONENT)),
        ));
    }

    #[Test]
    public function there_are_views_to_check(): void
    {
        $this->assertNotEmpty(self::views());
    }

    #[Test]
    public function the_one_dialog_dims_and_centres_on_the_same_element(): void
    {
        $markup = file_get_contents(self::COMPONENT);

        $this->assertMatchesRegularExpression(
            '/class="modal-backdrop modal-viewport/',
            $markup,
            'The dim and the centring must be one element, or the backdrop can never be clicked.'
        );

        $this->assertStringContainsString('wire:click.self', $markup,
            'A dialog needs a tap-outside that ignores taps inside it.');
    }

    #[Test]
    public function the_dialog_is_measured_against_the_visual_viewport(): void
    {
        $css = file_get_contents(__DIR__.'/../../resources/css/app.css');

        preg_match('/\.modal-viewport \{(.*?)\}/s', $css, $rule);

        $this->assertNotEmpty($rule, 'No .modal-viewport rule found.');
        $this->assertStringContainsString('--vv-top', $rule[1]);
        $this->assertStringContainsString('--vv-height', $rule[1],
            'Measured against the layout viewport, a dialog hides behind the keyboard.');
        $this->assertStringContainsString('--tab-bar-height', $rule[1],
            'The wall tab bar has to stay clear of it.');
    }

    #[Test]
    #[DataProvider('views')]
    public function nothing_hand_rolls_a_dialog(string $file): void
    {
        $markup = file_get_contents($file);
        $name = basename($file);

        $this->assertStringNotContainsString('modal-viewport', $markup,
            "{$name} builds its own dialog. Use <x-modal> so it gets the keyboard and the "
            .'backdrop right without having to remember to.');

        $this->assertDoesNotMatchRegularExpression(
            '/<div[^>]*class="fixed inset-0 z-\[?\d+\]? bg-(black|slate-900)/',
            $markup,
            "{$name} has a separate full-screen backdrop. A centring layer over it makes it unclickable."
        );
    }

    #[Test]
    #[DataProvider('views')]
    public function nothing_pins_a_form_to_the_layout_viewport(string $file): void
    {
        $markup = file_get_contents($file);

        // `fixed ... bottom-0` measures from the bottom of the *layout*
        // viewport, which on iOS is underneath the keyboard.
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*\bfixed\b[^"]*\bbottom-0\b[^"]*"/',
            $markup,
            basename($file).' pins something to the bottom of the layout viewport. '
            .'With a keyboard up that is off-screen — use <x-modal>.'
        );
    }
}
