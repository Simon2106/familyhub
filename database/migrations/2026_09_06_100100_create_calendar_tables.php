<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);              // google | icloud
            $table->string('label');                     // "Simon's Google"
            $table->string('external_account_id')->nullable(); // email / principal URL
            $table->text('credentials')->nullable();     // encrypted JSON (tokens / app password)
            $table->string('sync_token')->nullable();    // account-level, where the provider has one
            $table->string('status', 16)->default('pending'); // pending|ok|error|disabled
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_account_id']);
        });

        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 512);          // Google calendarId / CalDAV href
            $table->string('name');
            $table->string('colour', 7)->default('#2563eb');
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_writable')->default(true);
            $table->text('sync_token')->nullable();      // Google syncToken / CalDAV sync-token
            $table->string('ctag')->nullable();          // CalDAV change tag
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['calendar_account_id', 'external_id'], 'calendars_account_external_unique');
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 512);
            $table->string('recurrence_id')->nullable(); // RECURRENCE-ID for an exception instance
            $table->char('uid_hash', 64);                // sha256(external_id|recurrence_id)
            $table->string('title');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->text('rrule')->nullable();
            $table->string('source_hash', 64)->nullable(); // sha256 of the source payload
            $table->string('status', 16)->default('confirmed'); // confirmed|tentative|cancelled
            $table->timestamps();

            // (calendar_id, external_id, recurrence_id) is the logical identity, but a
            // 512-char utf8mb4 column overflows MySQL's 3072-byte key limit, so the
            // unique key is over a sha256 of that tuple, maintained by the Event model.
            $table->unique(['calendar_id', 'uid_hash'], 'events_calendar_uid_unique');
            $table->index('external_id');
            $table->index(['start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('calendars');
        Schema::dropIfExists('calendar_accounts');
    }
};
