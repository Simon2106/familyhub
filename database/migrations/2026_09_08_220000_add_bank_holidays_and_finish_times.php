<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank holidays, and the days school stops early.
 *
 * Bank holidays are national, so they are not attached to a household or a
 * school — every school is shut, and nobody should have to type them in twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('on')->unique();
            $table->string('title');

            // gov | computed — where this one came from, so a refresh from
            // GOV.UK can replace guesses without touching what it confirmed.
            $table->string('source', 16)->default('computed');

            $table->timestamps();
        });

        Schema::table('school_dates', function (Blueprint $table) {
            // On a term, the time it finishes on its last day. On an early
            // finish, the time it finishes that day.
            $table->time('finishes_at')->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('school_dates', function (Blueprint $table) {
            $table->dropColumn('finishes_at');
        });

        Schema::dropIfExists('bank_holidays');
    }
};
