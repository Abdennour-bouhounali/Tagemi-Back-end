<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'appointments_event_patient_index';

    /**
     * QueueService locks a patient's rows with FOR UPDATE on (event_id, patient_id).
     * Without this index the lock covers far more than that patient, and desks
     * working at once deadlock.
     */
    public function up(): void
    {
        if (!Schema::hasIndex('appointments', self::INDEX)) {
            Schema::table('appointments', fn (Blueprint $table) => $table->index(['event_id', 'patient_id'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('appointments', self::INDEX)) {
            Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
