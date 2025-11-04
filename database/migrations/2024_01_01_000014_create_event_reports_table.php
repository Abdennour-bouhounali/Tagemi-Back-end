<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            
            // Executive Summary
            $table->text('executive_summary')->nullable();
            $table->text('key_achievements')->nullable();
            $table->text('critical_issues')->nullable();
            
            // Attendance Metrics
            $table->integer('total_attendees')->nullable();
            $table->integer('registered_attendees')->nullable();
            $table->integer('actual_attendees')->nullable();
            $table->decimal('attendance_rate', 5, 2)->nullable();
            
            // Venue Information
            $table->string('venue_name')->nullable();
            $table->text('venue_feedback')->nullable();
            
            // Appointment Statistics
            $table->integer('total_appointments')->nullable();
            $table->integer('completed_appointments')->nullable();
            $table->integer('cancelled_appointments')->nullable();
            $table->integer('no_show_appointments')->nullable();
            $table->decimal('completion_rate', 5, 2)->nullable();
            
            // Team & Staff
            $table->integer('total_staff')->nullable();
            $table->integer('total_volunteers')->nullable();
            $table->text('team_performance_notes')->nullable();
            $table->text('outstanding_team_members')->nullable();
            
            // Logistics
            $table->text('logistics_summary')->nullable();
            $table->text('transportation_notes')->nullable();
            $table->text('accommodation_notes')->nullable();
            $table->text('catering_notes')->nullable();
            $table->text('equipment_notes')->nullable();
            
            // Budget & Finance
            $table->decimal('planned_budget', 12, 2)->nullable();
            $table->decimal('actual_expenses', 12, 2)->nullable();
            $table->decimal('budget_variance', 12, 2)->nullable();
            $table->text('major_expenses')->nullable();
            $table->text('cost_saving_measures')->nullable();
            
            // Marketing & Outreach
            $table->text('marketing_channels_used')->nullable();
            $table->integer('social_media_reach')->nullable();
            $table->integer('social_media_engagement')->nullable();
            $table->text('marketing_effectiveness')->nullable();
            
            // Feedback & Satisfaction
            $table->decimal('overall_satisfaction_rating', 3, 2)->nullable();
            $table->text('positive_feedback_summary')->nullable();
            $table->text('negative_feedback_summary')->nullable();
            $table->text('attendee_suggestions')->nullable();
            
            // Media Coverage
            $table->integer('media_mentions')->nullable();
            $table->text('media_outlets')->nullable();
            $table->text('media_highlights')->nullable();
            
            // Sponsors & Partners
            $table->text('sponsors_list')->nullable();
            $table->text('partnership_outcomes')->nullable();
            $table->text('sponsor_feedback')->nullable();
            
            // Analysis Points
            $table->text('positive_points')->nullable();
            $table->text('negative_points')->nullable();
            
            // Challenges & Solutions
            $table->text('challenges_faced')->nullable();
            $table->text('solutions_implemented')->nullable();
            
            // Technical Aspects
            $table->text('technical_issues')->nullable();
            $table->text('technical_solutions')->nullable();
            $table->text('infrastructure_notes')->nullable();
            
            // Safety & Security
            $table->text('security_measures')->nullable();
            $table->text('safety_incidents')->nullable();
            $table->text('health_protocols')->nullable();
            
            // Program Content
            $table->text('program_highlights')->nullable();
            $table->text('speaker_performance')->nullable();
            $table->text('session_feedback')->nullable();
            
            // Lessons Learned
            $table->text('what_went_well')->nullable();
            $table->text('what_went_wrong')->nullable();
            $table->text('unexpected_outcomes')->nullable();
            $table->text('best_practices_identified')->nullable();
            
            // Future Planning
            $table->text('future_tasks')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('improvements_needed')->nullable();
            $table->json('action_items')->nullable();
            $table->json('assignments')->nullable();
            
            // Additional Notes
            $table->text('general_notes')->nullable();
            $table->text('conclusion')->nullable();
            $table->text('acknowledgments')->nullable();
            
            // References
            $table->json('photo_references')->nullable();
            $table->json('document_references')->nullable();
            
            // Report Meta
            $table->string('report_status', 20)->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reports');
    }
};