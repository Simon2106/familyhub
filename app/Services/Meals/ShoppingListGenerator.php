<?php

namespace App\Services\Meals;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Household;
use App\Models\Meal;
use Illuminate\Support\Collection;

/**
 * Turns a week's planned meals into a shopping list.
 *
 * Only meals with a recipe attached can contribute anything — "leftovers" has
 * no ingredients — and that is reported rather than passed over in silence, so
 * a short list reads as "four of your meals were free text" instead of looking
 * like the merge went wrong.
 */
class ShoppingListGenerator
{
    public function generate(Household $household, string $from, string $to): ShoppingListResult
    {
        $meals = Meal::query()
            ->where('household_id', $household->id)
            ->between($from, $to)
            ->with('recipe')
            ->get();

        $list = self::listFor($household);
        $existing = $this->existingTitles($list);

        $added = 0;
        $alreadyThere = 0;

        foreach ($this->merge($meals) as $line) {
            if ($existing->contains($this->key($line['item']))) {
                $alreadyThere++;

                continue;
            }

            ChecklistItem::create([
                'checklist_id' => $list->id,
                'title' => ucfirst($line['item']),
                'quantity' => $this->amount($line),
            ]);

            $added++;
        }

        return new ShoppingListResult(
            list: $list,
            added: $added,
            alreadyThere: $alreadyThere,
            withRecipes: $meals->filter->hasIngredients()->count(),
            freeText: $meals->reject->hasIngredients()->count(),
        );
    }

    /** The household's shopping list, made on demand like the home to-do list. */
    public static function listFor(Household $household): Checklist
    {
        return Checklist::firstOrCreate(
            ['household_id' => $household->id, 'type' => 'shopping'],
            ['name' => 'Shopping'],
        );
    }

    /**
     * One line per ingredient-and-unit, quantities summed.
     *
     * Deduped by unit as well as by name on purpose: 200g of chorizo and two
     * tins of chorizo are not four hundred of anything, and a list that adds
     * them together is worse than one that lists both.
     *
     * @param  Collection<int, Meal>  $meals
     * @return list<array{item: string, unit: ?string, quantity: float|null}>
     */
    protected function merge(Collection $meals): array
    {
        $lines = [];

        foreach ($meals as $meal) {
            foreach ($meal->recipe?->ingredientList() ?? [] as $ingredient) {
                $item = trim(mb_strtolower($ingredient['item']));
                $unit = $ingredient['unit'] ?? null;
                $key = $item.'|'.($unit ?? '');

                if (! isset($lines[$key])) {
                    $lines[$key] = ['item' => $item, 'unit' => $unit, 'quantity' => null];
                }

                if (is_numeric($ingredient['quantity'] ?? null)) {
                    $lines[$key]['quantity'] = ($lines[$key]['quantity'] ?? 0) + (float) $ingredient['quantity'];
                }
            }
        }

        return array_values($lines);
    }

    /**
     * What was on the list before this run, open or ticked.
     *
     * Snapshotted rather than updated as we go, which is what lets 200g of
     * chorizo and two tins of it both be added — they are separate errands and
     * separate products — while a second run adds neither again.
     *
     * Matched on the ingredient name alone, not the unit: someone who wrote
     * "Onions — a bag" by hand has already dealt with onions, and a list that
     * argues with them is worse than one that trusts them.
     *
     * Ticked items count too: something bought this morning should not
     * reappear when the list is regenerated this afternoon.
     *
     * @return Collection<int, string>
     */
    protected function existingTitles(Checklist $list): Collection
    {
        return $list->items()->pluck('title')->map(fn (string $title) => $this->key($title))->values();
    }

    /** @param array{item: string, unit: ?string, quantity: float|null} $line */
    protected function amount(array $line): ?string
    {
        if ($line['quantity'] === null) {
            return $line['unit'];
        }

        // 0.5 rather than 0.50, and 200 rather than 200.00.
        $quantity = rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.');

        return trim($quantity.' '.($line['unit'] ?? ''));
    }

    /** Loose enough that "Onions" and "onion " are the same thing. */
    protected function key(string $title): string
    {
        return rtrim(trim(mb_strtolower($title)), 's');
    }
}
