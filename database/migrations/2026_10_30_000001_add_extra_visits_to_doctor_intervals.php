<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visits added by hand to a doctor's interval, on top of the ones computed
 * from its length (visits_count now holds both). They are spread over the
 * interval's hours, first hours first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_intervals', function (Blueprint $t) {
            $t->unsignedSmallInteger('extra_visits')->default(0)->after('visit_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_intervals', function (Blueprint $t) {
            $t->dropColumn('extra_visits');
        });
    }
};
