<?php

namespace App\Models;

use Database\Factories\HomeTileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One chosen entity, as it appears on the wall's Home tab. */
#[Fillable(['household_id', 'entity_id', 'domain', 'section_override', 'name', 'label', 'area', 'sort_order'])]
class HomeTile extends Model
{
    /** @use HasFactory<HomeTileFactory> */
    use HasFactory;

    /** Where the picker files an entity HA has not put in a room. */
    public const UNGROUPED = 'Everything else';

    /**
     * The sections of the Home tab, in the order they are shown.
     *
     * A section decides where a tile appears and nothing else. What a tile can
     * actually do still follows its Home Assistant domain: a Sonoff plug filed
     * under Lights is still a `switch` entity, and calling `light.toggle` on it
     * would simply fail.
     */
    public const SECTIONS = [
        'lights' => 'Lights',
        'sockets' => 'Sockets & plugs',
        'heating' => 'Heating',
        'covers' => 'Blinds & covers',
        'runnable' => 'Scenes & scripts',
        'other' => 'Other',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0];

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Which section this belongs in.
     *
     * The household's choice if they made one, otherwise whatever the domain
     * implies. Storing the default as null means changing these defaults later
     * moves every tile nobody has had an opinion about.
     *
     * Deliberately not named after the column it reads. Eloquent treats a
     * method matching an attribute name as a relationship and calls it to find
     * out, so `section()` reading `section` recurses until the process runs out
     * of memory — a long way from anything that points at the cause.
     */
    public function section(): string
    {
        $chosen = $this->section_override;

        if ($chosen && array_key_exists($chosen, self::SECTIONS)) {
            return $chosen;
        }

        return self::defaultSectionFor($this->domain);
    }

    public function sectionLabel(): string
    {
        return self::SECTIONS[$this->section()];
    }

    /** Where an entity lands before anyone has an opinion about it. */
    public static function defaultSectionFor(?string $domain): string
    {
        return match ($domain) {
            'light' => 'lights',
            'switch' => 'sockets',
            'climate' => 'heating',
            'cover' => 'covers',
            'scene', 'script' => 'runnable',
            default => 'other',
        };
    }

    /** What the household calls it, falling back to what HA calls it. */
    public function title(): string
    {
        return $this->label ?: $this->name;
    }
}
