<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_accounts', function (Blueprint $table) {
            // Discovered once at pairing time, then reused on every sync.
            $table->string('principal_url', 512)->nullable()->after('external_account_id');
            $table->string('calendar_home_url', 512)->nullable()->after('principal_url');
        });

        Schema::table('calendars', function (Blueprint $table) {
            // Not every CalDAV server advertises sync-collection; those fall
            // back to a time-ranged calendar-query.
            $table->boolean('supports_sync_collection')->default(false)->after('ctag');
        });

        Schema::table('events', function (Blueprint $table) {
            // A CalDAV event is a resource at a URL inside the calendar
            // collection, and its ETag is what makes a safe conditional write
            // possible. Both are absent for events that have not been pushed.
            $table->string('href', 512)->nullable()->after('external_id');
            $table->string('etag')->nullable()->after('href');

            // Set when a local edit has not yet reached the server, so a failed
            // push can be retried instead of silently lost.
            $table->boolean('needs_push')->default(false)->after('source_hash');
            $table->timestamp('pushed_at')->nullable()->after('needs_push');

            $table->index('needs_push');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['needs_push']);
            $table->dropColumn(['href', 'etag', 'needs_push', 'pushed_at']);
        });

        Schema::table('calendars', function (Blueprint $table) {
            $table->dropColumn('supports_sync_collection');
        });

        Schema::table('calendar_accounts', function (Blueprint $table) {
            $table->dropColumn(['principal_url', 'calendar_home_url']);
        });
    }
};
