<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super admin switch, per event: every specialty officer (check admin) of the
 * event may register a patient in their specialty even when the patient
 * already holds the event's maximum number of specialties.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('specialty_admins_can_exceed_limit')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('specialty_admins_can_exceed_limit');
        });
    }
};
