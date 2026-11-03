<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fake doctors of the training event: hidden from the doctors registry,
 * deleted and recreated with the training event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $t) {
            $t->boolean('is_simulation')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', fn (Blueprint $t) => $t->dropColumn('is_simulation'));
    }
};
