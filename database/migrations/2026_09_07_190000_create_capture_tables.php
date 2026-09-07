<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            // email | photo | pdf | text | url | share
            $table->string('source', 16);
            // pending | processing | reviewing | done | failed
            $table->string('status', 16)->default('pending');

            $table->string('subject')->nullable();
            $table->string('sender')->nullable();
            $table->longText('body_text')->nullable();

            // The provider's raw payload, kept so a bad extraction can be
            // re-run later without asking the sender to forward it again.
            $table->longText('raw_payload')->nullable();

            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'status']);
        });

        Schema::create('capture_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capture_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('filename');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size')->default(0);

            // Set when the original is a format Claude cannot read (HEIC) and
            // we converted a copy for the request.
            $table->string('converted_path', 512)->nullable();
            $table->timestamps();
        });

        Schema::create('capture_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capture_id')->constrained()->cascadeOnDelete();

            // event | task | note
            $table->string('type', 16)->default('event');
            $table->string('title');
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();
            $table->text('notes')->nullable();

            // What the model thought about who it is for, as free text; the
            // reviewer turns that into a real member.
            $table->string('member_hint')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0); // 0-100

            // pending | accepted | rejected
            $table->string('status', 16)->default('pending');

            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('calendar_id')->nullable()->constrained()->nullOnDelete();

            // What accepting it created, so an accept can be traced or undone.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['capture_id', 'status']);
            $table->index(['status', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_items');
        Schema::dropIfExists('capture_attachments');
        Schema::dropIfExists('captures');
    }
};
