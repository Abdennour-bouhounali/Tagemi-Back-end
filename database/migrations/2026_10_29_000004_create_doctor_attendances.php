<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a doctor actually came and left on a visit day (recorded by the super
 * admin). The first arrival of a specialty's doctors opens its queue, the
 * last departure closes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete()->cascadeOnUpdate();
            $t->foreignId('day_id')->constrained('days')->cascadeOnDelete()->cascadeOnUpdate();
            $t->timestamp('arrived_at')->nullable();
            $t->timestamp('left_at')->nullable();
            $t->string('note', 255)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $t->timestamps();

            $t->unique(['doctor_id', 'day_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_attendances');
    }
};
