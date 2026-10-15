<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each child needs on each day of the school week.
 *
 * PE kit on Tuesday, library book on Thursday: the things a family remembers
 * at eight o'clock or not at all. Per weekday rather than per date because
 * that is how a school timetable actually works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->json('school_notes')->nullable()->after('birthday');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('school_notes');
        });
    }
};
