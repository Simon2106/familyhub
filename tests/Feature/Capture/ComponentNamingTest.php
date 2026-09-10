<?php

namespace Tests\Feature\Capture;

use App\Models\Household;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Component;
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
            ['kids.my-day'],
            ['kids.pin'],
            ['kids.ledger'],
            ['admin.chores'],
            ['admin.routines'],
            ['admin.rewards'],
            ['kids.parent'],
            ['admin.home'],
            ['home.panel'],
            ['search.box'],
            ['assistant.ask'],
            ['meals.ideas'],
            ['meals.history'],
            ['meals.page'],
            ['meals.how-was-it'],
            ['meals.tonight'],
            ['home.switches'],
            ['display.wall'],
            ['notify.settings'],
            ['phone.home'],
        ];
    }

    /**
     * Overrides that are the point of writing a component.
     *
     * Everything else Livewire declares is off limits — see below.
     *
     * @var list<string>
     */
    public const INTENTIONAL_OVERRIDES = [
        'render', 'mount', 'boot', 'booted', 'hydrate', 'dehydrate',
        'updated', 'updating', 'rules', 'messages', 'validationAttributes',
        'exceptionHandler', 'placeholder',
    ];

    /**
     * Names Livewire owns through magic, which reflection cannot see.
     *
     * `slots` is the one that cost an afternoon. It is not a declared member —
     * Livewire 4 resolves it through __get — so a #[Computed] slots() is
     * shadowed by an empty one and every write guarded on it fails with no
     * exception and no validation error. Reflection finds nothing to warn
     * about, hence this list.
     *
     * @var list<string>
     */
    public const MAGIC_NAMES = ['slots'];

    /**
     * Names Livewire itself owns, read off the base class rather than listed.
     *
     * A hand-kept list only holds the collisions already paid for. `slots` cost
     * an afternoon — a #[Computed] slots() is shadowed by Livewire 4's own
     * empty one, so every write guarded on it failed with no exception and no
     * validation error. `tap` cost another: Livewire\Component has one, and an
     * override with a different signature is a fatal error at render. Asking
     * the base class means the next one is caught before it is written.
     *
     * @return list<string>
     */
    public static function reservedNames(): array
    {
        $base = new ReflectionClass(Component::class);

        $names = collect($base->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->merge($base->getProperties(\ReflectionProperty::IS_PUBLIC))
            ->map(fn ($member) => $member->getName())
            ->reject(fn (string $name) => str_starts_with($name, '__'))
            ->merge(self::MAGIC_NAMES)
            ->diff(self::INTENTIONAL_OVERRIDES)
            ->unique()
            ->values();

        return $names->all();
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
        $reserved = collect(self::reservedNames());

        $declared = collect($reflection->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->merge($reflection->getProperties(\ReflectionProperty::IS_PUBLIC))
            ->reject(fn ($member) => $member->class !== $reflection->getName())
            ->map(fn ($member) => $member->getName());

        $clashes = $declared->intersect($reserved)->values();

        $this->assertTrue(
            $clashes->isEmpty(),
            "{$name} declares ".$clashes->implode(', ').', which Livewire owns. '
            .'It will be shadowed by the framework, or clash fatally on signature — rename it.'
        );
    }

    #[Test]
    public function the_reserved_list_is_read_from_livewire_rather_than_remembered(): void
    {
        $reserved = self::reservedNames();

        // `tap` and `dispatch` come from the base class, so the guard follows
        // Livewire if they ever move; `slots` is magic and has to be listed.
        foreach (['tap', 'dispatch'] as $name) {
            $this->assertContains($name, $reserved, 'Reflection should find declared members.');
        }

        $this->assertContains('slots', $reserved, 'Magic names still have to be listed by hand.');

        // ...and the overrides a component exists to write.
        foreach (self::INTENTIONAL_OVERRIDES as $name) {
            $this->assertNotContains($name, $reserved);
        }
    }
}
