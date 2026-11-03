<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visit timing: the specialty officer marks the patient "دخل" (Entered) with
 * the doctor who receives them, then "إجتاز" (Completed).
 * - entered_at / completed_at: time spent with the doctor
 * - visit_group: patients of one family who entered together
 * - visit_seconds: this patient's share of the visit (group time / group size)
 * appointments.doctor_id (already there) holds the doctor.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('entered_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->string('visit_group', 36)->nullable();
                $t->unsignedInteger('visit_seconds')->nullable();
            });
        }

        Schema::table('appointments', function (Blueprint $t) {
            $t->index(['doctor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropIndex(['doctor_id', 'status']));
        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['entered_at', 'completed_at', 'visit_group', 'visit_seconds']));
        }
    }
};
