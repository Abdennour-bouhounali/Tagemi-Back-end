<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed an appointment by hand, and when: time changed, deleted,
 * specialty added, moved to the front of the queue. appointment_id has no
 * foreign key so the line stays after the appointment is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('appointment_id')->nullable()->index();
            $t->unsignedBigInteger('event_id')->nullable();
            $t->unsignedBigInteger('patient_id')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 40);
            $t->json('details')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['event_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_logs');
    }
};
