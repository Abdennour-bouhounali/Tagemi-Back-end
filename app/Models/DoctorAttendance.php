<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A doctor's arrival and departure on one day of an event specialty. */
class DoctorAttendance extends Model
{
    protected $fillable = [
        'doctor_id',
        'day_id',
        'arrived_at',
        'left_at',
        'note',
        'recorded_by',
    ];

    protected $casts = [
        'arrived_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function isPresent(): bool
    {
        return $this->arrived_at !== null && $this->left_at === null;
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function day()
    {
        return $this->belongsTo(Day::class);
    }
}
