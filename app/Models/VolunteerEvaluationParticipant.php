<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An anonymous volunteer: a token kept on their phone and the role they picked. */
class VolunteerEvaluationParticipant extends Model
{
    protected $fillable = ['volunteer_evaluation_id', 'token_hash', 'role_id'];

    protected $hidden = ['token_hash'];

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(VolunteerEvaluation::class, 'volunteer_evaluation_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(VolunteerEvaluationAnswer::class, 'participant_id');
    }
}
