<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deadline-aware to-dos.
 *
 * A to-do with a due date now has a date it starts being shown, so a form due
 * in six weeks can be captured today without cluttering the wall for six
 * weeks. Left null, it is derived from the household's lead time; set, it
 * overrides that for this one task.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->date('surface_from')->nullable()->after('due_on');

            // The thing the deadline is attached to — the vaccination the
            // consent form is for — so the card can say what it is in aid of.
            $table->foreignId('event_id')->nullable()->after('surface_from')->constrained()->nullOnDelete();

            // The all-day "Reminder: …" event mirrored into iCloud, kept so a
            // tick can remove it and a changed due date can move it.
            $table->foreignId('mirror_event_id')->nullable()->after('event_id')
                ->constrained('events')->nullOnDelete();

            // Both wall and phone ask "what is showing today?" constantly.
            $table->index(['checklist_id', 'is_done', 'surface_from']);
        });

        Schema::table('capture_items', function (Blueprint $table) {
            // The model names the event a deadline belongs to rather than
            // inventing an id; accepting resolves the name to a real event.
            $table->string('for_event_title')->nullable()->after('member_hint');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropIndex(['checklist_id', 'is_done', 'surface_from']);
            $table->dropConstrainedForeignId('event_id');
            $table->dropConstrainedForeignId('mirror_event_id');
            $table->dropColumn('surface_from');
        });

        Schema::table('capture_items', function (Blueprint $table) {
            $table->dropColumn('for_event_title');
        });
    }
};
