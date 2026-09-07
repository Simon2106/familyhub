<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per extraction call: the attachment it read, or the message
        // body. Keeping them apart is what lets the review card say where an
        // item came from and show what the model made of that document.
        Schema::create('capture_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capture_id')->constrained()->cascadeOnDelete();
            $table->string('label');            // "flu-letter.pdf" | "message body"
            $table->string('kind', 16);         // attachment | body
            $table->text('summary')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->index(['capture_id', 'kind']);
        });

        Schema::table('capture_items', function (Blueprint $table) {
            $table->foreignId('capture_source_id')->nullable()->after('capture_id')
                ->constrained('capture_sources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('capture_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('capture_source_id'));

        Schema::dropIfExists('capture_sources');
    }
};
