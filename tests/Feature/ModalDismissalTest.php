<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every centred dialog must be dismissable by tapping outside it.
 *
 * `.modal-viewport` is a full-screen fixed layer. Given a separate backdrop
 * element underneath it, the viewport covers the backdrop completely and the
 * backdrop's click handler can never fire — so "tap outside to close" silently
 * stops working everywhere, and a dialog whose own buttons are awkward to reach
 * cannot be dismissed at all. The two are one element now, and this keeps them
 * that way.
 */
class ModalDismissalTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function views(): array
    {
        // A plain path: data providers run before the application is booted.
        $files = glob(__DIR__.'/../../resources/views/components/**/*.blade.php') ?: [];

        return array_values(array_map(
            fn (string $file) => [$file],
            array_filter($files, fn (string $file) => str_contains(file_get_contents($file), 'modal-viewport')),
        ));
    }

    #[Test]
    public function there_are_modals_to_check(): void
    {
        $this->assertNotEmpty(self::views(), 'Found no modals, so this guard proves nothing.');
    }

    #[Test]
    #[DataProvider('views')]
    public function a_centred_dialog_dims_and_dismisses_on_the_same_element(string $file): void
    {
        $markup = file_get_contents($file);
        $name = basename($file);

        preg_match_all('/<div[^>]*class="[^"]*modal-viewport[^"]*"[^>]*>/', $markup, $matches);

        $this->assertNotEmpty($matches[0], "{$name} uses modal-viewport but no element carries it.");

        foreach ($matches[0] as $tag) {
            $this->assertStringContainsString('modal-backdrop', $tag,
                "{$name} has a modal-viewport without the dim on the same element. "
                .'A separate backdrop underneath it can never be clicked.');

            $this->assertMatchesRegularExpression('/wire:click\.self=|x-on:click\.self=/', $tag,
                "{$name} has a modal-viewport with no way to dismiss it by tapping outside. "
                .'Use wire:click.self so taps inside the dialog do not close it.');
        }
    }

    #[Test]
    #[DataProvider('views')]
    public function no_separate_full_screen_backdrop_is_left_underneath(string $file): void
    {
        $markup = file_get_contents($file);

        $this->assertDoesNotMatchRegularExpression(
            '/<div class="fixed inset-0 z-\[?\d+\]? bg-(black|slate-900)/',
            $markup,
            basename($file).' still has a separate full-screen backdrop. '
            .'modal-viewport sits on top of it, so its click handler is dead.'
        );
    }
}
