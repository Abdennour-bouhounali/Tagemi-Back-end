<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training event: a permanent event used to teach staff and patients the whole
 * flow (registration, visit day). The admin moves its date before each session.
 * It is never auto-archived and the apps show a "not real" notice for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->boolean('is_simulation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('is_simulation'));
    }
};
