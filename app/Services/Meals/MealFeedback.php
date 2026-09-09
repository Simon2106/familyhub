<?php

namespace App\Services\Meals;

use App\Models\Household;
use App\Models\Meal;
use App\Models\Member;
use App\Models\Recipe;
use App\Models\RecipeRating;
use Carbon\CarbonImmutable;

/**
 * "How was it?" — asked once, about a dinner that has been and gone.
 *
 * The asking is the delicate part. A prompt that reappears is a prompt people
 * learn to swipe past without reading, so answering and waving away are the
 * same act as far as the meal is concerned: both stamp feedback_at, and
 * neither is ever asked again.
 */
class MealFeedback
{
    /** How far back to bother asking. Last Tuesday's dinner is nobody's memory. */
    public const WITHIN_DAYS = 3;

    /** The most recent dinner nobody has been asked about, if there is one. */
    public function awaiting(Household $household): ?Meal
    {
        $today = $household->todayLocal();

        return Meal::query()
            ->where('household_id', $household->id)
            ->whereNull('feedback_at')
            // Strictly before today: tonight has not happened yet.
            ->where('on', '<', $today->toDateString())
            ->where('on', '>=', $today->subDays(self::WITHIN_DAYS)->toDateString())
            ->with('recipe')
            ->orderByDesc('on')
            ->first();
    }

    /**
     * Record what somebody thought, and stop asking.
     *
     * A meal typed straight into the planner — "fajitas" — has no idea behind
     * it, so rating one makes the idea and links the meal to it. That is the
     * promotion the brief asks for, arriving at the moment it is most useful:
     * the family has just eaten the thing and has an opinion about it.
     */
    public function rate(Meal $meal, Member $member, int $stars, ?string $note = null): Recipe
    {
        $recipe = $meal->recipe ?? $this->promote($meal);

        $this->star($recipe, $member, $stars, $note);

        $meal->forceFill(['feedback_at' => now()])->save();

        return $recipe;
    }

    /** Asked and answered with a shrug. Never ask again. */
    public function dismiss(Meal $meal): void
    {
        $meal->forceFill(['feedback_at' => now()])->save();
    }

    /** An adult's stars. One row per person per idea, updated in place. */
    public function star(Recipe $recipe, Member $member, int $stars, ?string $note = null): RecipeRating
    {
        $rating = RecipeRating::firstOrNew([
            'recipe_id' => $recipe->id,
            'member_id' => $member->id,
        ]);

        $rating->stars = max(1, min(5, $stars));

        if ($note !== null) {
            $rating->note = trim($note) ?: null;
        }

        $rating->save();

        return $rating;
    }

    /**
     * A child's thumb, from the wall.
     *
     * Tapping the same thumb again takes it back — on a screen with no undo
     * and no PIN, the way out of a mis-tap has to be the same tap.
     */
    public function thumb(Recipe $recipe, Member $member, int $direction): ?RecipeRating
    {
        $rating = RecipeRating::firstOrNew([
            'recipe_id' => $recipe->id,
            'member_id' => $member->id,
        ]);

        $direction = $direction >= 0 ? 1 : -1;

        if ($rating->exists && $rating->thumbs === $direction) {
            $rating->delete();

            return null;
        }

        $rating->thumbs = $direction;
        $rating->stars = null;
        $rating->save();

        return $rating;
    }

    /**
     * Turn a typed-in meal into an idea, keeping every dinner it has been.
     *
     * Every meal with the same title becomes the idea's history, so promoting
     * "fajitas" in September does not throw away the four times it was eaten
     * before anyone thought to save it.
     */
    public function promote(Meal $meal): Recipe
    {
        $recipe = Recipe::query()
            ->where('household_id', $meal->household_id)
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim($meal->title))])
            ->first()
            ?? Recipe::create([
                'household_id' => $meal->household_id,
                'title' => trim($meal->title),
                'source_kind' => 'text',
                'status' => 'ready',
            ]);

        Meal::query()
            ->where('household_id', $meal->household_id)
            ->whereNull('recipe_id')
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim($meal->title))])
            ->update(['recipe_id' => $recipe->id]);

        $meal->refresh();

        return $recipe;
    }

    /** What the household has eaten, newest week first. */
    public function history(Household $household, int $weeks = 12): array
    {
        $from = $household->weekStart()->subWeeks($weeks - 1);

        $meals = Meal::query()
            ->where('household_id', $household->id)
            ->where('on', '>=', $from->toDateString())
            ->where('on', '<', $household->todayLocal()->toDateString())
            ->with('recipe')
            ->orderByDesc('on')
            ->get();

        return $meals
            ->groupBy(fn (Meal $meal) => CarbonImmutable::parse($meal->on)->startOfWeek()->toDateString())
            ->all();
    }
}
