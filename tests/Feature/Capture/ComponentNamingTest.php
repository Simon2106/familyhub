<?php

namespace Tests\Feature\Capture;

use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ['recipes.box'],
            ['meals.plan'],
            ['meals.shopping'],
        ];
    }

    /**
     * Names Livewire itself owns on the component.
     *
     * `slots` is here because it cost an afternoon: Livewire 4 has a slots
     * feature, so a #[Computed] slots() is shadowed by an empty collection.
     * Every write guarded on it then failed silently — no exception, no
     * validation error, simply nothing saved.
     */
    public static function reserved(): array
    {
        return [['slots'], ['id'], ['props'], ['view'], ['redirect'], ['dispatch'], ['skipRender']];
    }

    #[Test]
    #[DataProvider('components')]
    public function no_public_method_is_shadowed_by_a_public_property(string $name): void
    {
        Household::factory()->create();

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

    #[Test]
    #[DataProvider('components')]
    public function no_member_collides_with_a_name_livewire_owns(string $name): void
    {
        Household::factory()->create();

        $reflection = new ReflectionClass(Livewire::test($name)->instance());
        $reserved = collect(self::reserved())->flatten();

        $declared = collect($reflection->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->merge($reflection->getProperties(\ReflectionProperty::IS_PUBLIC))
            ->reject(fn ($member) => $member->class !== $reflection->getName())
            ->map(fn ($member) => $member->getName());

        $clashes = $declared->intersect($reserved)->values();

        $this->assertTrue(
            $clashes->isEmpty(),
            "{$name} declares ".$clashes->implode(', ').', which Livewire owns. '
            .'It will be shadowed by the framework and fail silently — rename it.'
        );
    }
}
