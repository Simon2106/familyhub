<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notify` is a method Laravel already gives every user.
 *
 * Notifiable::notify() exists on the model, so a column of that name makes
 * Eloquent call it looking for a relationship and recurse until the process
 * runs out of memory — with a stack trace nowhere near either. ModelNamingTest
 * caught it before it ever ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->renameColumn('notify', 'notify_settings'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->renameColumn('notify_settings', 'notify'));
    }
};
