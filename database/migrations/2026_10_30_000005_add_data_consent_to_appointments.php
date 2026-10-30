<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the patient accepted the processing of their personal and health data
 * (law 18-07). Empty for bookings made before the consent step existed and
 * for bookings entered by staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('data_consent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('data_consent_at'));
        }
    }
};
