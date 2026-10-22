<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dilatation becomes a hidden station attached to a specialty (تصحيح النظر):
 * - event_specialties.has_dilatation: this specialty sends patients to dilatation
 * - appointments.dilatation_sent_at / dilatation_started_at: when the patient
 *   was sent, and when the dilatation officer received them (timer start)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_specialties', function (Blueprint $t) {
            $t->boolean('has_dilatation')->default(false);
        });

        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('dilatation_sent_at')->nullable();
                $t->timestamp('dilatation_started_at')->nullable();
            });
        }

        DB::table('event_specialties')
            ->whereIn('specialty_id', DB::table('specialties')->where('name', 'like', '%تصحيح النظر%')->pluck('id'))
            ->update(['has_dilatation' => true]);
    }

    public function down(): void
    {
        Schema::table('event_specialties', fn (Blueprint $t) => $t->dropColumn('has_dilatation'));
        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['dilatation_sent_at', 'dilatation_started_at']));
        }
    }
};
