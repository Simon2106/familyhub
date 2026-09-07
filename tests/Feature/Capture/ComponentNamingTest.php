<?php

namespace Tests\Feature\Capture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Livewire exposes public properties and public methods on the same client-side
 * object, so a method sharing a name with a property is shadowed by it —
 * `$wire.open()` resolves to the boolean and silently does nothing. It looks
 * fine server-side, which is why this is checked by reflection rather than by
 * calling the method.
 */
class ComponentNamingTest extends TestCase
{
    use RefreshDatabase;

    public static function components(): array
    {
        return [
            ['capture.intake'],
            ['capture.review'],
            ['todos.panel'],
            ['display.lists'],
            ['phone.event-editor'],
            ['admin.calendars'],
            ['admin.places'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('components')]
    public function no_public_method_is_shadowed_by_a_public_property(string $name): void
    {
        \App\Models\Household::factory()->create();

        $instance = Livewire::test($name)->instance();
        $reflection = new ReflectionClass($instance);

        $properties = collect($reflection->getProperties(\ReflectionProperty::IS_PUBLIC))
            ->map(fn ($p) => $p->getName());

        $clashes = collect($reflection->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->map(fn ($m) => $m->getName())
            ->intersect($properties)
            ->values();

        $this->assertTrue(
            $clashes->isEmpty(),
            "{$name} has method(s) shadowed by a property of the same name: ".$clashes->implode(', ')
        );
    }
}
