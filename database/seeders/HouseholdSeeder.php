<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use App\Support\EnvSeedSpec;
use Illuminate\Database\Seeder;

/**
 * Idempotent: safe to re-run after editing the FAMILYHUB_SEED_* variables.
 * Existing rows are updated in place rather than duplicated, and passwords are
 * only written on creation so a seed re-run never resets a changed password.
 */
class HouseholdSeeder extends Seeder
{
    public function run(): void
    {
        $household = Household::query()->orderBy('id')->first();

        $household = $household
            ? tap($household)->update(['name' => config('familyhub.household_name')])
            : Household::create([
                'name' => config('familyhub.household_name'),
                'timezone' => config('familyhub.timezone'),
            ]);

        foreach (EnvSeedSpec::members() as $i => $spec) {
            $member = Member::firstOrNew([
                'household_id' => $household->id,
                'name' => $spec['name'],
            ]);

            $member->colour = $spec['colour'];
            $member->is_child = $spec['is_child'];
            $member->sort_order = $i;

            // Only set the pin when one is supplied, so seeding cannot wipe a
            // pin that was changed in the admin UI.
            if ($spec['pin'] !== null) {
                $member->pin = $spec['pin'];
            }

            $member->save();

            // So "Simon Williams" matches a title that just says "Simon".
            $member->ensureFirstNameAlias();
        }

        foreach (EnvSeedSpec::users() as $spec) {
            $user = User::firstOrNew(['email' => $spec['email']]);

            $user->name = $spec['name'];
            $user->household_id = $household->id;
            $user->member_id = Member::where('household_id', $household->id)
                ->where('name', $spec['name'])
                ->value('id');

            if (! $user->exists) {
                $user->password = $spec['password'];
                $user->email_verified_at = now();
            }

            $user->save();
        }

        $this->command?->info(sprintf(
            'Household "%s": %d members, %d logins.',
            $household->name,
            $household->members()->count(),
            $household->users()->count(),
        ));
    }
}
