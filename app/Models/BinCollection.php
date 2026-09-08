<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Database\Factories\BinCollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One bin, one day. */
#[Fillable(['household_id', 'on', 'name', 'kind'])]
class BinCollection extends Model
{
    /** @use HasFactory<BinCollectionFactory> */
    use HasFactory;

    /**
     * The colours a household actually thinks in.
     *
     * Paper and electricals are their own kinds rather than lumped in with
     * recycling: this council collects them as separate rounds on the same
     * day, and one row per round is the only way two of them can sit side by
     * side without colliding.
     */
    public const KINDS = [
        'refuse' => ['label' => 'Rubbish', 'colour' => '#475569', 'icon' => '🗑️'],
        'recycling' => ['label' => 'Recycling', 'colour' => '#2563eb', 'icon' => '♻️'],
        'paper' => ['label' => 'Paper & card', 'colour' => '#0891b2', 'icon' => '📦'],
        'garden' => ['label' => 'Garden', 'colour' => '#16a34a', 'icon' => '🌿'],
        'food' => ['label' => 'Food', 'colour' => '#ca8a04', 'icon' => '🍎'],
        'electricals' => ['label' => 'Electricals', 'colour' => '#7c3aed', 'icon' => '🔌'],
        'other' => ['label' => 'Bins', 'colour' => '#94a3b8', 'icon' => '🗑️'],
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'other'];

    protected function casts(): array
    {
        return ['on' => CalendarDate::class];
    }

    /**
     * Which bin a council's wording means.
     *
     * Councils name these however they like — "Domestic Waste", "Blue Lidded
     * Bin", "Mixed Dry Recycling". Matched loosely and on purpose: getting it
     * wrong shows the wrong colour, which is better than showing nothing.
     */
    public static function kindFor(string $summary): string
    {
        $text = mb_strtolower($summary);

        return match (true) {
            str_contains($text, 'garden') || str_contains($text, 'green waste') => 'garden',
            str_contains($text, 'food') || str_contains($text, 'caddy') => 'food',
            str_contains($text, 'electric') || str_contains($text, 'wee') && str_contains($text, 'small') => 'electricals',
            // Before the general recycling test, or "paper and card" is
            // swallowed by the round it is collected alongside.
            str_contains($text, 'paper') || str_contains($text, 'card') => 'paper',
            str_contains($text, 'recycl') || str_contains($text, 'blue') || str_contains($text, 'glass') => 'recycling',
            str_contains($text, 'refuse') || str_contains($text, 'rubbish') || str_contains($text, 'general')
                || str_contains($text, 'domestic') || str_contains($text, 'household') || str_contains($text, 'black') => 'refuse',
            default => 'other',
        };
    }

    public function label(): string
    {
        return self::KINDS[$this->kind]['label'] ?? self::KINDS['other']['label'];
    }

    public function colour(): string
    {
        return self::KINDS[$this->kind]['colour'] ?? self::KINDS['other']['colour'];
    }

    public function icon(): string
    {
        return self::KINDS[$this->kind]['icon'] ?? self::KINDS['other']['icon'];
    }

    /** @param Builder<BinCollection> $query */
    public function scopeUpcoming(Builder $query, string $from): void
    {
        $query->where('on', '>=', $from)->orderBy('on');
    }
}
