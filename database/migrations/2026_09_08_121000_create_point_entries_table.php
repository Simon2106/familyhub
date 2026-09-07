<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The points ledger: append-only, one row per thing that happened.
 *
 * A balance is the sum of the rows, never a stored number, and nothing is ever
 * deleted or edited. Taking points back writes a reversal. That matters more
 * here than in most ledgers, because the account holder is seven and the whole
 * point is that they can see where their stars went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // Signed. Awards are positive, reversals and spending negative.
            $table->integer('points');

            // award | reversal | redemption | adjustment
            $table->string('kind', 16);

            // Said in the child's own words on their ledger.
            $table->string('reason');

            // What caused it — a chore instance, a redemption, or nothing at
            // all for a parent's manual adjustment.
            $table->nullableMorphs('source');

            $table->timestamps();

            $table->index(['member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_entries');
    }
};
