<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklists', function (Blueprint $table) {
            // The one list surfaced on the wall's home view. A flag rather than
            // a second model: a home to-do list is just a checklist with a job.
            $table->boolean('is_home_list')->default(false)->after('type');
        });

        Schema::table('checklist_items', function (Blueprint $table) {
            // Who the to-do is for, as opposed to done_by_member_id, which
            // records who eventually ticked it.
            $table->foreignId('member_id')->nullable()->after('checklist_id')
                ->constrained('members')->nullOnDelete();

            // A date, not a datetime: "bins out Tuesday" has no time of day.
            $table->date('due_on')->nullable()->after('notes');

            $table->index(['checklist_id', 'is_done', 'due_on']);
        });

        // Promote an existing "To do" list rather than leaving households with
        // two lists that look the same.
        $existing = DB::table('checklists')->where('type', 'todo')->orderBy('id')->first();

        if ($existing) {
            DB::table('checklists')->where('id', $existing->id)->update(['is_home_list' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropIndex(['checklist_id', 'is_done', 'due_on']);
            $table->dropConstrainedForeignId('member_id');
            $table->dropColumn('due_on');
        });

        Schema::table('checklists', fn (Blueprint $table) => $table->dropColumn('is_home_list'));
    }
};
