<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Volunteer debrief of one event (one-to-one with the event). */
class VolunteerEvaluation extends Model
{
    protected $fillable = [
        'event_id', 'title', 'roles', 'axes', 'recommendations',
        'is_open', 'phase', 'ends_at', 'duration_min',
    ];

    protected $casts = [
        'roles' => 'array',
        'axes' => 'array',
        'recommendations' => 'array',
        'is_open' => 'boolean',
        'ends_at' => 'integer',
        'duration_min' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(VolunteerEvaluationParticipant::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(VolunteerEvaluationAnswer::class);
    }

    /** The questions as volunteers see them. */
    public function publicConfig(): array
    {
        return [
            'event' => ['id' => $this->event_id, 'name' => $this->event?->name],
            'title' => $this->title,
            'roles' => $this->roles ?? [],
            'axes' => $this->axes ?? [],
            'is_open' => $this->is_open,
        ];
    }

    public function session(): array
    {
        return [
            'is_open' => $this->is_open,
            'phase' => $this->phase,
            'ends_at' => $this->ends_at,
            'duration_min' => $this->duration_min,
        ];
    }
}
