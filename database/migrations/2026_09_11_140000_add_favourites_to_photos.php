<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photographs the family has picked out.
 *
 * The screensaver already favours recent ones over old ones. A favourite is
 * the family saying "this one, more often" about a picture that is neither —
 * the one from the holiday three years ago that everybody stops to look at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->boolean('is_favourite')->default(false)->after('is_hidden');
            $table->index(['household_id', 'is_favourite']);
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'is_favourite']);
            $table->dropColumn('is_favourite');
        });
    }
};
