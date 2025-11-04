<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->unique(['event_id', 'doctor_id'], 'ux_event_doctor');
        });

        Schema::create('event_specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('specialty_id')->constrained('specialties')->cascadeOnDelete()->cascadeOnUpdate();
            $table->boolean('is_saturated')->default(false);
            $table->integer('max_number')->default(100);
            $table->time('start_time')->default('08:00:00');
            $table->integer('addition_capacity')->default(0);
            $table->string('flag', 20)->default('Open');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->unique(['event_id', 'specialty_id'], 'ux_event_specialty_event_specialty');
        });

        Schema::create('event_specialty_doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_specialty_id')->constrained('event_specialties')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->unique(['event_specialty_id', 'doctor_id'], 'ux_event_specialty_doctor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_specialty_doctors');
        Schema::dropIfExists('event_specialties');
        Schema::dropIfExists('event_doctors');
    }
};