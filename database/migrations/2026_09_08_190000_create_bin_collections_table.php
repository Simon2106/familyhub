<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the bins go out, and which ones.
 *
 * Kept as its own small table rather than as calendar events: these are not
 * things anyone edits, they are replaced wholesale every night from the
 * council's feed, and they must never find their way back into iCloud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bin_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->date('on');
            $table->string('name');

            // refuse | recycling | garden | food | other — what colour bin.
            $table->string('kind', 16)->default('other');

            $table->timestamps();

            // The council can list two bins on one day; it must not list the
            // same one twice.
            $table->unique(['household_id', 'on', 'kind']);
            $table->index(['household_id', 'on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bin_collections');
    }
};
