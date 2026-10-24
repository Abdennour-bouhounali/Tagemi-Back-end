<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visit day: the super admin opens a specialty's queue when its doctor is there.
 * - event_specialties.opened_on: the specialty is open on that date only, so
 *   every visit day starts with all specialties closed
 * - appointments.checked_in_at: arrival time, the order in which patients who
 *   wait for a closed specialty are sent to it once it opens
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_specialties', function (Blueprint $t) {
            $t->date('opened_on')->nullable();
        });

        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('checked_in_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('event_specialties', fn (Blueprint $t) => $t->dropColumn('opened_on'));
        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('checked_in_at'));
        }
    }
};
