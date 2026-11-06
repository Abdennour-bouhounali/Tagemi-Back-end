<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's break on a visit day: out for now but coming back, so unlike a
 * departure it keeps the queue open and the doctor's planned visits counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_attendances', function (Blueprint $t) {
            $t->timestamp('on_break_since')->nullable()->after('left_at');
        });
        Schema::table('doctor_attendance_periods', function (Blueprint $t) {
            $t->boolean('ended_by_break')->default(false)->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_attendance_periods', function (Blueprint $t) {
            $t->dropColumn('ended_by_break');
        });
        Schema::table('doctor_attendances', function (Blueprint $t) {
            $t->dropColumn('on_break_since');
        });
    }
};
