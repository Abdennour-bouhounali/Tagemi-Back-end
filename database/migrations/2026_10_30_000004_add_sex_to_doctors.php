<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's sex (male / female). Empty for doctors registered before; the
 * form asks for it at the next edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $t) {
            $t->string('sex', 6)->nullable()->after('family_name');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', fn (Blueprint $t) => $t->dropColumn('sex'));
    }
};
