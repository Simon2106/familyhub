<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscribed calendars, in the table calendars already live in.
 *
 * A fixtures list or a club's season is somebody else's calendar read
 * read-only, which is the same shape as an iCloud calendar minus the writing.
 * Giving it its own table would mean teaching the wall, the phone, search and
 * the assistant about a second kind of event; giving it a row here means none
 * of them ever find out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_accounts', function (Blueprint $table) {
            // Text, not the existing external_account_id: subscription URLs
            // carry long opaque tokens and that column is 255 and indexed.
            $table->text('feed_url')->nullable()->after('external_account_id');

            $table->unsignedSmallInteger('refresh_minutes')->nullable()->after('sync_token');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_accounts', function (Blueprint $table) {
            $table->dropColumn(['feed_url', 'refresh_minutes']);
        });
    }
};
