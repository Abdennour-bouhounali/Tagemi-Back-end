<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A doctor's working interval on one day, e.g. 08:30-12:00 with 20-minute visits. */
class DoctorInterval extends Model
{
    protected $fillable = [
        'day_id',
        'doctor_id',
        'start_time',
        'end_time',
        'visit_minutes',
        'visits_count',
    ];

    protected $casts = [
        'visit_minutes' => 'integer',
        'visits_count' => 'integer',
    ];

    public function day()
    {
        return $this->belongsTo(Day::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }
}
