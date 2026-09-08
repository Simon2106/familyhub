<?php

namespace App\Models;

use App\Casts\CalendarDate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A national bank holiday. Not editable, and not per household. */
#[Fillable(['on', 'title', 'source'])]
class BankHoliday extends Model
{
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['source' => 'computed'];

    protected function casts(): array
    {
        return ['on' => CalendarDate::class];
    }

    /** @param Builder<BankHoliday> $query */
    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->where('on', '>=', $from)->where('on', '<=', $to)->orderBy('on');
    }
}
