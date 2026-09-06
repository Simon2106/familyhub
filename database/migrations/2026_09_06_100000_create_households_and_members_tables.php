<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Nullable so Household::displayTimezone() can fall back to config.
            $table->string('timezone')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('colour', 7)->default('#2563eb');
            $table->string('avatar_path')->nullable();
            $table->boolean('is_child')->default(false);
            $table->string('pin')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'sort_order']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->after('household_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
            $table->dropConstrainedForeignId('household_id');
        });

        Schema::dropIfExists('members');
        Schema::dropIfExists('households');
    }
};
