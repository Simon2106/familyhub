<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What sort of thing an alias is.
 *
 * `name` is what a member or place is called, and is matched against event
 * titles. `domain` is an email domain that belongs to them — the school's, the
 * club's — which must never be matched against a title but is a strong hint
 * about who a forwarded email concerns.
 *
 * One table rather than two, because they are the same idea: another string
 * that means this member or this place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aliases', function (Blueprint $table) {
            $table->string('kind', 16)->default('name')->after('alias');
            $table->index(['kind', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::table('aliases', function (Blueprint $table) {
            $table->dropIndex(['kind', 'alias']);
            $table->dropColumn('kind');
        });
    }
};
