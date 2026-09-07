<?php

use App\Models\Member;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Backfill first-name aliases for members created before attribution
     * existed, so matching works on day one without anyone editing anything.
     */
    public function up(): void
    {
        Member::query()->with('aliases')->chunkById(200, function ($members) {
            foreach ($members as $member) {
                $member->ensureFirstNameAlias();
            }
        });
    }

    public function down(): void
    {
        // Aliases are user data by this point; removing them would be
        // destructive and the table is dropped by the migration that made it.
    }
};
