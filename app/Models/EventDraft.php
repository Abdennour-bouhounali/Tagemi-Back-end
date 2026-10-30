<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An unfinished new-event form of a super admin (see EventDraftController). */
class EventDraft extends Model
{
    protected $fillable = ['user_id', 'name', 'data'];

    protected $casts = ['data' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
