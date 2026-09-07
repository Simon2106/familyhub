<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A model method must not share a name with one of its columns.
 *
 * Eloquent resolves a missing attribute by asking whether a method of that name
 * exists and, if it does, calling it to see if it returns a relationship. A
 * `section()` method reading a `section` column therefore calls itself until the
 * process runs out of memory — and the stack trace lands in HasAttributes,
 * nowhere near the model that caused it.
 *
 * It only misbehaves when the attribute is *absent* from the instance, which is
 * exactly the state of a row created without that column, so it hides from the
 * obvious tests. Hence reflection.
 */
class ModelNamingTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: class-string<Model>}> */
    public static function models(): array
    {
        $models = [];

        // A plain path, not app_path(): data providers run before the
        // application is booted.
        foreach (glob(__DIR__.'/../../app/Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (is_subclass_of($class, Model::class)) {
                $models[] = [$class];
            }
        }

        return $models;
    }

    #[Test]
    public function there_are_models_to_check(): void
    {
        $this->assertNotEmpty(self::models(), 'The model discovery found nothing, so this guard proves nothing.');
    }

    #[Test]
    #[DataProvider('models')]
    public function no_method_shares_a_name_with_a_column(string $class): void
    {
        /** @var Model $model */
        $model = new $class;

        if (! Schema::hasTable($model->getTable())) {
            $this->markTestSkipped("{$class} has no table.");
        }

        $columns = collect(Schema::getColumnListing($model->getTable()));

        $reflection = new ReflectionClass($model);

        $methods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->reject(fn (ReflectionMethod $method) => $method->class !== $reflection->getName())
            ->reject(fn (ReflectionMethod $method) => $method->isStatic())
            ->map(fn (ReflectionMethod $method) => $method->getName());

        $clashes = $methods->intersect($columns)->values();

        $this->assertTrue(
            $clashes->isEmpty(),
            Str::afterLast($class, '\\').' has method(s) named after a column: '.$clashes->implode(', ').'. '
            .'Eloquent will call them looking for a relationship and recurse. Rename the method, or the column.'
        );
    }
}
