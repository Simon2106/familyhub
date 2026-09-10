<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Being told about things, per person and per device.
 *
 * A subscription belongs to a browser, not to a person: the same parent has
 * one on their phone and another on the iPad, and revoking one must not
 * silence the other. Preferences belong to the person, because "tell me about
 * the review inbox" is a thing they mean everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The browser's own address for this device. Unique because a
            // second subscribe from the same browser replaces the first.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();

            $table->string('p256dh');
            $table->string('auth');

            // "Simon's phone", so a list of devices can be pruned by hand.
            $table->string('device_label')->nullable();

            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * Which triggers this person wants, as a blob. A table of five
             * booleans per user would be five rows to keep in step with a
             * list that changes whenever a feature is added.
             */
            $table->json('notify')->nullable()->after('password');
        });

        Schema::create('notices_sent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // event_reminder, review_waiting, and so on.
            $table->string('trigger', 32);

            // What it was about — an event id, a date, a list id. Together
            // with the trigger this is what stops the same thing being sent
            // twice, which is the difference between a useful notification
            // and one people switch off.
            $table->string('subject', 191);

            // Held rather than sent, for the digest.
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('digested_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'trigger', 'subject'], 'notice_once');
            $table->index(['user_id', 'digested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices_sent');
        Schema::dropIfExists('push_subscriptions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notify'));
    }
};
