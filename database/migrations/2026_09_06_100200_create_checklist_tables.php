<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "List" is a reserved word in PHP and cannot be a class name, so the
        // brief's List/ListItem models are Checklist/ChecklistItem here.
        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 24)->default('todo'); // todo|shopping|packing
            $table->string('icon', 32)->nullable();
            $table->string('colour', 7)->default('#2563eb');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'sort_order']);
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('done_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('title');
            $table->string('quantity', 64)->nullable(); // "2 tins", used by the Phase 5 shopping list
            $table->text('notes')->nullable();
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['checklist_id', 'is_done', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklists');
    }
};
