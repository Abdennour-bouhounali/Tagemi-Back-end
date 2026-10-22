<?php

use App\Models\Appointment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * - is_priority: the appointment sits in the priority block at the head of
 *   its queue (special case, back from dilatation). Priority patients are
 *   ordered among themselves by arrival.
 * - identity_key: normalized name + birthday, so the same person cannot be
 *   booked twice for the same specialty of an event (enforced by a unique
 *   index, so it also holds under concurrent submissions).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'is_priority')) {
                    $t->boolean('is_priority')->default(false)->after('is_special');
                }
                if (!Schema::hasColumn($table, 'identity_key')) {
                    $t->char('identity_key', 40)->nullable()->after('patient_id');
                }
            });
        }

        // Legacy "priority" was orderList = 0 on a queued appointment
        DB::table('appointments')
            ->whereIn('status', ['Present', 'Commingsoon'])
            ->where('orderList', 0)
            ->update(['is_priority' => true]);

        // Fill identity keys. When old data already holds the same person twice
        // in one specialty, the oldest row keeps the key and the others stay
        // NULL (logged for manual review) so the unique index can be created.
        $taken = [];
        DB::table('appointments')
            ->select('id', 'event_id', 'event_specialty_id', 'full_name', 'birthday')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$taken) {
                foreach ($rows as $row) {
                    $key = Appointment::identityKey($row->full_name, $row->birthday);
                    $slot = "{$row->event_id}|{$row->event_specialty_id}|{$key}";
                    if (isset($taken[$slot])) {
                        Log::warning('Possible duplicate booking kept without identity key', [
                            'appointment_id' => $row->id,
                            'same_as' => $taken[$slot],
                        ]);
                        continue;
                    }
                    $taken[$slot] = $row->id;
                    DB::table('appointments')->where('id', $row->id)->update(['identity_key' => $key]);
                }
            });

        Schema::table('appointments', function (Blueprint $t) {
            $t->unique(['event_id', 'event_specialty_id', 'identity_key'], 'appointments_unique_person_per_specialty');
            $t->index(['event_id', 'identity_key'], 'appointments_event_identity_index');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropUnique('appointments_unique_person_per_specialty');
            $t->dropIndex('appointments_event_identity_index');
        });

        foreach (['appointments', 'appointments_archive'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['is_priority', 'identity_key']);
            });
        }
    }
};
