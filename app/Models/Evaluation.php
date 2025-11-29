<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    protected $fillable = [
        'event_id',
        'appointment_id',
        'age_range',
        'gender',
        'specialty', // Keep for backward compatibility
        'specialties', // New field for multiple specialties
        'specialty_other', // For "other" specialty text
        'overall_organization',
        'medical_quality',
        'staff_performance',
        'registration_ease',
        'platform_organization',
        'suggested_specialties',
        'positive_points',
        'negative_points',
        'suggestions',
        'would_recommend',
        'contact_name',
        'contact_phone',
        'contact_email',
        'ip_address',
        'user_agent',
        'submitted_at',
        'is_processed',
        'processed_at',
        'admin_notes',
    ];

    protected $casts = [
        'would_recommend' => 'boolean',
        'is_processed' => 'boolean',
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
        'overall_organization' => 'integer',
        'medical_quality' => 'integer',
        'staff_performance' => 'integer',
        'registration_ease' => 'integer',
        'platform_organization' => 'integer',
        'specialties' => 'array', // Cast JSON to array
    ];

    /**
     * Get the event that owns the evaluation
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the appointment that owns the evaluation
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Scope for unprocessed evaluations
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    /**
     * Scope for processed evaluations
     */
    public function scopeProcessed($query)
    {
        return $query->where('is_processed', true);
    }

    /**
     * Scope for specific event
     */
    public function scopeForEvent($query, $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    /**
     * Get rating label in Arabic
     */
    public function getRatingLabel($rating)
    {
        $labels = [
            1 => 'ضعيف',
            2 => 'مقبول',
            3 => 'جيد',
            4 => 'جيد جدا',
            5 => 'ممتاز',
        ];
        
        return $labels[$rating] ?? 'غير محدد';
    }

    /**
     * Get average rating for this evaluation
     */
    public function getAverageRatingAttribute()
    {
        $ratings = [
            $this->overall_organization,
            $this->medical_quality,
            $this->staff_performance,
            $this->registration_ease,
            $this->platform_organization,
        ];
        
        return round(array_sum($ratings) / count($ratings), 2);
    }

    /**
     * Mark as processed
     */
    public function markAsProcessed($notes = null)
    {
        $this->update([
            'is_processed' => true,
            'processed_at' => now(),
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Get formatted specialties string
     */
    public function getFormattedSpecialtiesAttribute()
    {
        if (!$this->specialties) {
            return $this->specialty ?? 'غير محدد';
        }
        
        $specs = is_array($this->specialties) ? $this->specialties : [];
        
        if ($this->specialty_other) {
            $specs[] = $this->specialty_other;
        }
        
        return implode(', ', $specs);
    }
}