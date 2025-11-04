<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments_archive', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('event_specialty_id');
            $table->unsignedBigInteger('day_id');
            $table->unsignedBigInteger('hour_id');
            $table->unsignedBigInteger('doctor_id')->nullable();
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
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            
            $table->index('user_id', 'idx_archive_user_id');
            $table->index('event_specialty_id', 'idx_archive_event_specialty_id');
            $table->index('day_id', 'idx_archive_day_id');
            $table->index('hour_id', 'idx_archive_hour_id');
            $table->index('doctor_id', 'idx_archive_doctor_id');
            $table->index('event_id', 'idx_archive_event_id');
            $table->index(['event_id', 'status'], 'idx_archive_event_status');
            $table->index('archived_at', 'idx_archive_archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments_archive');
    }
};