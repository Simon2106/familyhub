<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lists beyond Shopping and To do.
 *
 * The table already held a name, a colour and a sort order — it was only ever
 * the app that assumed two of them. This adds the one thing a packing list or
 * a birthday wishlist needs that a household list does not: somebody whose it
 * is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklists', function (Blueprint $table) {
            // Null is the household's own, which is what Shopping and To do
            // have always been.
            $table->foreignId('member_id')->nullable()->after('household_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checklists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
        });
    }
};
