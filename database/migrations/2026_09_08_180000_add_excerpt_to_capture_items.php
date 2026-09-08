<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The words an item was read from.
 *
 * A review card asks somebody to trust a date the model pulled out of a
 * six-page newsletter. Showing the sentence it came from turns that from an
 * act of faith into something they can check in two seconds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_items', function (Blueprint $table) {
            $table->text('excerpt')->nullable()->after('for_event_title');
            $table->unsignedSmallInteger('source_page')->nullable()->after('excerpt');
        });
    }

    public function down(): void
    {
        Schema::table('capture_items', function (Blueprint $table) {
            $table->dropColumn(['excerpt', 'source_page']);
        });
    }
};
