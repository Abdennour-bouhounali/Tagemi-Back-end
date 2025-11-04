<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('event_specialty_id')->constrained('event_specialties')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('day_id')->constrained('days')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('hour_id')->constrained('hours')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('doctor_id')->nullable()->constrained('doctors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('full_name');
            $table->date('birthday');
            $table->string('state', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('sex', 10);
            $table->string('phone', 20);
            $table->string('phone2', 20);
            $table->string('diseases', 400)->default('');
            $table->integer('patient_id');
            $table->integer('specialty_order')->nullable();
            $table->integer('position')->default(1);
            $table->integer('orderList')->default(0);
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('Pending');
            $table->boolean('is_special')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_reason')->nullable();
            $table->json('medical_documents')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->index('event_id', 'idx_appointments_event_id');
            $table->index(['event_id', 'status'], 'idx_appointments_event_status');
            $table->index('archived_at', 'idx_appointments_archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};