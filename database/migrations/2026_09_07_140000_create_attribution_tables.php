<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Members and places both answer to several names, so one polymorphic
        // table serves both rather than two near-identical ones.
        Schema::create('aliases', function (Blueprint $table) {
            $table->id();
            $table->morphs('aliasable');
            $table->string('alias', 120);
            $table->timestamps();

            $table->unique(['aliasable_type', 'aliasable_id', 'alias'], 'aliases_owner_alias_unique');
        });

        // A named organisation that turns up in event titles: a school, a
        // workplace, a club.
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 16)->default('other'); // school|work|club|other
            $table->timestamps();

            $table->unique(['household_id', 'name']);
        });

        Schema::create('member_place', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();

            // Whether a match on this place should pull this member in on its
            // own. "Ice" belongs to both parents, but only Simon is added
            // automatically; Jenna needs her own name in the title.
            $table->boolean('include_automatically')->default(true);
            $table->timestamps();

            $table->unique(['member_id', 'place_id']);
        });

        Schema::create('event_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // What put this member on the event, for explaining the guess in
            // the UI: alias | place | calendar | manual
            $table->string('reason', 16)->default('alias');
            $table->timestamps();

            $table->unique(['event_id', 'member_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            // 'manual' means a person chose the members by hand; attribution
            // must then leave the event alone, including on later syncs.
            $table->string('attribution', 16)->default('auto')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('attribution'));

        Schema::dropIfExists('event_member');
        Schema::dropIfExists('member_place');
        Schema::dropIfExists('places');
        Schema::dropIfExists('aliases');
    }
};
