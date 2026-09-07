<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What points are for, and the record of them being spent.
 *
 * A redemption keeps its own copy of the name and the cost. Deleting a reward
 * from the catalogue must not rewrite what a child remembers saving up for,
 * and re-pricing one must not restate what it cost at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('cost');
            $table->string('image_path')->nullable();
            $table->string('image_disk', 32)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'is_active']);
        });

        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->nullable()->constrained()->nullOnDelete();

            // Snapshots, so history survives the catalogue changing.
            $table->string('name');
            $table->unsignedInteger('cost');

            // pending | granted | declined
            $table->string('status', 16)->default('pending');

            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->timestamps();

            $table->index(['household_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
        Schema::dropIfExists('rewards');
    }
};
