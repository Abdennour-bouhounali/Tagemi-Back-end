<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The super admin tags some reception accounts: they may book a patient more
 * specialties than the event allows. Appointments booked past the limit are
 * marked so the check-in limit check does not count them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_exceed_specialty_limit')->default(false);
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('beyond_limit')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_exceed_specialty_limit');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('beyond_limit');
        });
    }
};
