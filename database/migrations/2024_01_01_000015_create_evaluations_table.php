<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            
            // Demographics
            $table->string('age_range')->nullable();
            $table->string('gender')->nullable();
            $table->string('specialty')->nullable();
            
            // Ratings (1-5 scale)
            $table->integer('overall_organization')->nullable();
            $table->integer('medical_quality')->nullable();
            $table->integer('staff_performance')->nullable();
            $table->integer('registration_ease')->nullable();
            $table->integer('platform_organization')->nullable();
            
            // Feedback
            $table->text('suggested_specialties')->nullable();
            $table->text('positive_points')->nullable();
            $table->text('negative_points')->nullable();
            $table->text('suggestions')->nullable();
            $table->boolean('would_recommend')->default(true);
            
            // Contact Info (Optional)
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            
            // Meta
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            
            // Admin Processing
            $table->boolean('is_processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('admin_notes')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
