<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's working interval on one day of a specialty, e.g. 08:30-12:00
 * with 20-minute visits = 10 visits. The bookable hours of the day (hours
 * table) are computed from the intervals of all its doctors, since patients
 * book an hour and every doctor of a specialty works in the same room.
 * Days created before this keep number_per_hour; new days leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_intervals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('day_id')->constrained('days')->cascadeOnDelete()->cascadeOnUpdate();
            $t->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()->cascadeOnUpdate();
            $t->time('start_time');
            $t->time('end_time');
            $t->unsignedSmallInteger('visit_minutes');
            $t->unsignedSmallInteger('visits_count');
            $t->timestamps();

            $t->index(['doctor_id', 'day_id']);
        });

        Schema::table('days', function (Blueprint $t) {
            $t->integer('number_per_hour')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_intervals');

        Schema::table('days', function (Blueprint $t) {
            $t->integer('number_per_hour')->nullable(false)->default(6)->change();
        });
    }
};
