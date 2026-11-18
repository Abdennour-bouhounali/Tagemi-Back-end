<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One volunteer's answer to one axis: done or skipped. */
class VolunteerEvaluationAnswer extends Model
{
    protected $fillable = [
        'volunteer_evaluation_id', 'participant_id', 'axis_id', 'status',
        'overall', 'ratings', 'responses', 'positives', 'negatives',
    ];

    protected $casts = [
        'overall' => 'integer',
        'ratings' => 'array',
        'responses' => 'array',
        'positives' => 'array',
        'negatives' => 'array',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(VolunteerEvaluationParticipant::class, 'participant_id');
    }

    public function toClient(): array
    {
        return [
            'status' => $this->status,
            'overall' => $this->overall,
            'ratings' => (object) ($this->ratings ?? []),
            'responses' => (object) ($this->responses ?? []),
            'positives' => $this->positives ?? [],
            'negatives' => $this->negatives ?? [],
            'saved_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
