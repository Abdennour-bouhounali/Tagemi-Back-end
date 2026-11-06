<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each time a doctor came in and went out on a visit day (a mid-day break
 * gives two periods). doctor_attendances stays the summary of the day: first
 * arrival, and left_at while the doctor is out. Days already recorded get one
 * period from that summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_attendance_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('doctor_attendance_id')->constrained('doctor_attendances')->cascadeOnDelete()->cascadeOnUpdate();
            // Nullable: a NOT NULL first timestamp gets ON UPDATE CURRENT_TIMESTAMP on MySQL
            $t->timestamp('arrived_at')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->timestamps();
        });

        DB::table('doctor_attendance_periods')->insertUsing(
            ['doctor_attendance_id', 'arrived_at', 'left_at', 'created_at', 'updated_at'],
            DB::table('doctor_attendances')->whereNotNull('arrived_at')
                ->select('id', 'arrived_at', 'left_at', 'created_at', 'updated_at')
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_attendance_periods');
    }
};
