<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            // The family's own note — "Joey won't eat the sauce", "needs twice
            // the rice". Separate from source_note, which explains where the
            // card came from rather than what the household thinks of it.
            $table->text('notes')->nullable()->after('source_note');
        });

        Schema::table('meals', function (Blueprint $table) {
            // When "how was it?" was answered or waved away. One column, so
            // the question is asked once and never nags twice.
            $table->timestamp('feedback_at')->nullable()->after('recipe_id');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', fn (Blueprint $table) => $table->dropColumn('notes'));
        Schema::table('meals', fn (Blueprint $table) => $table->dropColumn('feedback_at'));
    }
};
