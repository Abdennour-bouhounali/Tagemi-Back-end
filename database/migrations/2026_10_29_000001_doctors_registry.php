<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctors registry kept by the super admin: identity, wilaya, specialty,
 * sector (public / private / both) and a login account (users row, role
 * doctor) created with the doctor. The table was never used before (empty
 * in production), so full_name and email are replaced outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $t) {
            $t->dropColumn(['full_name', 'email']);
        });

        Schema::table('doctors', function (Blueprint $t) {
            $t->string('first_name', 100)->after('id');
            $t->string('family_name', 100)->after('first_name');
            $t->string('wilaya_code', 2)->nullable()->after('phone');
            $t->foreignId('specialty_id')->nullable()->after('wilaya_code')
                ->constrained('specialties')->restrictOnDelete()->cascadeOnUpdate();
            $t->string('sector', 10)->default('public')->after('specialty_id'); // public | private | both
            $t->foreignId('user_id')->nullable()->unique()->after('sector')
                ->constrained('users')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropConstrainedForeignId('specialty_id');
            $t->dropColumn(['first_name', 'family_name', 'wilaya_code', 'sector']);
        });

        Schema::table('doctors', function (Blueprint $t) {
            $t->string('full_name')->default('')->after('id');
            $t->string('email')->nullable()->after('full_name');
        });
    }
};
