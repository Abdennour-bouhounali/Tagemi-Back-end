<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReport extends Model
{
    protected $fillable = [
        'event_id',
        
        // Executive Summary
        'executive_summary',
        'key_achievements',
        'critical_issues',
        
        // Event Overview
        'total_attendees',
        'registered_attendees',
        'actual_attendees',
        'attendance_rate',
        'venue_name',
        'venue_feedback',
        
        // Performance Metrics
        'total_appointments',
        'completed_appointments',
        'cancelled_appointments',
        'no_show_appointments',
        'completion_rate',
        
        // Team Performance
        'total_staff',
        'total_volunteers',
        'team_performance_notes',
        'outstanding_team_members',
        
        // Logistics & Operations
        'logistics_summary',
        'transportation_notes',
        'accommodation_notes',
        'catering_notes',
        'equipment_notes',
        
        // Financial Summary
        'planned_budget',
        'actual_expenses',
        'budget_variance',
        'major_expenses',
        'cost_saving_measures',
        
        // Marketing & Promotion
        'marketing_channels_used',
        'social_media_reach',
        'social_media_engagement',
        'marketing_effectiveness',
        
        // Attendee Feedback
        'overall_satisfaction_rating',
        'positive_feedback_summary',
        'negative_feedback_summary',
        'attendee_suggestions',
        
        // Media Coverage
        'media_mentions',
        'media_outlets',
        'media_highlights',
        
        // Partnerships & Sponsors
        'sponsors_list',
        'partnership_outcomes',
        'sponsor_feedback',
        
        // Main Content
        'positive_points',
        'negative_points',
        'challenges_faced',
        'solutions_implemented',
        
        // Technical & Infrastructure
        'technical_issues',
        'technical_solutions',
        'infrastructure_notes',
        
        // Security & Safety
        'security_measures',
        'safety_incidents',
        'health_protocols',
        
        // Program Content
        'program_highlights',
        'speaker_performance',
        'session_feedback',
        
        // Lessons Learned
        'what_went_well',
        'what_went_wrong',
        'unexpected_outcomes',
        'best_practices_identified',
        
        // Future Planning
        'future_tasks',
        'recommendations',
        'improvements_needed',
        'action_items',
        
        // Assignments
        'assignments',
        
        // Additional
        'general_notes',
        'conclusion',
        'acknowledgments',
        
        // Attachments
        'photo_references',
        'document_references',
        
        // Metadata
        'report_status',
        'approved_at',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'assignments' => 'array',
        'action_items' => 'array',
        'photo_references' => 'array',
        'document_references' => 'array',
        'attendance_rate' => 'decimal:2',
        'completion_rate' => 'decimal:2',
        'planned_budget' => 'decimal:2',
        'actual_expenses' => 'decimal:2',
        'budget_variance' => 'decimal:2',
        'overall_satisfaction_rating' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Auto-calculate fields
    public function calculateMetrics()
    {
        if ($this->registered_attendees && $this->actual_attendees) {
            $this->attendance_rate = ($this->actual_attendees / $this->registered_attendees) * 100;
        }

        if ($this->total_appointments && $this->completed_appointments) {
            $this->completion_rate = ($this->completed_appointments / $this->total_appointments) * 100;
        }

        if ($this->planned_budget && $this->actual_expenses) {
            $this->budget_variance = $this->actual_expenses - $this->planned_budget;
        }
    }
}