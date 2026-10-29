<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a doctor must never delete patients' appointments: the foreign key
 * was ON DELETE CASCADE, it becomes SET NULL. Production named the constraint
 * fk_appointments_doctor, fresh databases appointments_doctor_id_foreign, so
 * the name is read from the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replaceForeignKey(fn (Blueprint $t) => $t->foreign('doctor_id')
            ->references('id')->on('doctors')->nullOnDelete()->cascadeOnUpdate());
    }

    public function down(): void
    {
        $this->replaceForeignKey(fn (Blueprint $t) => $t->foreign('doctor_id')
            ->references('id')->on('doctors')->cascadeOnDelete()->cascadeOnUpdate());
    }

    private function replaceForeignKey(callable $add): void
    {
        $name = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'appointments')
            ->where('COLUMN_NAME', 'doctor_id')
            ->where('REFERENCED_TABLE_NAME', 'doctors')
            ->value('CONSTRAINT_NAME');

        Schema::table('appointments', function (Blueprint $t) use ($name, $add) {
            if ($name) {
                $t->dropForeign($name);
            }
            $add($t);
        });
    }
};
