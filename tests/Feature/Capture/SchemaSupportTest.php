<?php

namespace Tests\Feature\Capture;

use App\Services\Capture\ExtractionSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Structured outputs accept a subset of JSON Schema, and an unsupported keyword
 * is a 400 at request time — visible only in production, one keyword per
 * attempt, because the API reports the first problem it finds.
 *
 * Checked here against the documented list instead.
 */
class SchemaSupportTest extends TestCase
{
    use RefreshDatabase;

    /** Rejected outright by output_config.format.schema. */
    public const UNSUPPORTED = [
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
        'minLength', 'maxLength', 'pattern',
        'maxItems', 'uniqueItems', 'minProperties', 'maxProperties',
        'oneOf', 'not', 'if', 'then', 'else', 'patternProperties',
    ];

    public const SUPPORTED_FORMATS = [
        'date-time', 'time', 'date', 'duration', 'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid',
    ];

    #[Test]
    public function the_extraction_schema_uses_no_unsupported_keywords(): void
    {
        $found = [];
        $this->walk(ExtractionSchema::schema(), '$', function (array $node, string $path) use (&$found) {
            foreach (self::UNSUPPORTED as $keyword) {
                if (array_key_exists($keyword, $node)) {
                    $found[] = "{$path}.{$keyword}";
                }
            }
        });

        $this->assertSame([], $found, 'Unsupported keyword(s) in the schema: '.implode(', ', $found));
    }

    #[Test]
    public function nullability_uses_any_of_rather_than_a_union_type(): void
    {
        // ["string", "null"] is rejected; anyOf is the supported spelling.
        $unions = [];
        $this->walk(ExtractionSchema::schema(), '$', function (array $node, string $path) use (&$unions) {
            if (isset($node['type']) && is_array($node['type'])) {
                $unions[] = $path;
            }
        });

        $this->assertSame([], $unions, 'Union type(s) at: '.implode(', ', $unions));
    }

    #[Test]
    public function every_object_forbids_additional_properties(): void
    {
        $missing = [];
        $this->walk(ExtractionSchema::schema(), '$', function (array $node, string $path) use (&$missing) {
            if (($node['type'] ?? null) === 'object' && ($node['additionalProperties'] ?? null) !== false) {
                $missing[] = $path;
            }
        });

        $this->assertSame([], $missing, 'Object(s) without additionalProperties:false at: '.implode(', ', $missing));
    }

    #[Test]
    public function every_object_lists_its_required_properties(): void
    {
        $missing = [];
        $this->walk(ExtractionSchema::schema(), '$', function (array $node, string $path) use (&$missing) {
            if (($node['type'] ?? null) === 'object') {
                $declared = array_keys($node['properties'] ?? []);
                $required = $node['required'] ?? [];

                if (array_diff($declared, $required) !== []) {
                    $missing[] = $path.' ('.implode(', ', array_diff($declared, $required)).')';
                }
            }
        });

        $this->assertSame([], $missing, 'Properties not marked required: '.implode('; ', $missing));
    }

    #[Test]
    public function min_items_is_only_ever_zero_or_one(): void
    {
        $bad = [];
        $this->walk(ExtractionSchema::schema(), '$', function (array $node, string $path) use (&$bad) {
            if (isset($node['minItems']) && ! in_array($node['minItems'], [0, 1], true)) {
                $bad[] = $path;
            }
        });

        $this->assertSame([], $bad);
    }

    #[Test]
    public function any_string_format_is_one_the_api_accepts(): void
    {
        $bad = [];
        $this->walk(ExtractionSchema::schema(), '$', function (array $node, string $path) use (&$bad) {
            if (isset($node['format']) && ! in_array($node['format'], self::SUPPORTED_FORMATS, true)) {
                $bad[] = "{$path} ({$node['format']})";
            }
        });

        $this->assertSame([], $bad);
    }

    #[Test]
    public function enums_hold_only_scalars(): void
    {
        $bad = [];
        $this->walk(ExtractionSchema::schema(), '$', function (array $node, string $path) use (&$bad) {
            foreach ($node['enum'] ?? [] as $value) {
                if (! is_scalar($value) && $value !== null) {
                    $bad[] = $path;
                }
            }
        });

        $this->assertSame([], $bad);
    }

    #[Test]
    public function the_confidence_range_is_stated_in_words_since_it_cannot_be_constrained(): void
    {
        $confidence = ExtractionSchema::schema()['properties']['items']['items']['properties']['confidence'];

        $this->assertSame('integer', $confidence['type']);
        // minimum/maximum are rejected, so the range has to be said instead —
        // and ExtractionParser clamps whatever comes back.
        $this->assertStringContainsString('0 to 100', $confidence['description']);
    }

    #[Test]
    public function the_schema_survives_a_json_round_trip(): void
    {
        // It travels as JSON; an unencodable value would fail at request time.
        $json = json_encode(ExtractionSchema::schema(), JSON_THROW_ON_ERROR);

        $this->assertIsArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Visit every schema node, including inside properties, items and anyOf.
     *
     * @param  array<string, mixed>  $node
     */
    protected function walk(array $node, string $path, callable $check): void
    {
        $check($node, $path);

        foreach ($node['properties'] ?? [] as $name => $child) {
            if (is_array($child)) {
                $this->walk($child, "{$path}.{$name}", $check);
            }
        }

        if (isset($node['items']) && is_array($node['items'])) {
            $this->walk($node['items'], "{$path}[]", $check);
        }

        foreach (['anyOf', 'allOf'] as $combinator) {
            foreach ($node[$combinator] ?? [] as $i => $child) {
                if (is_array($child)) {
                    $this->walk($child, "{$path}.{$combinator}[{$i}]", $check);
                }
            }
        }
    }
}
