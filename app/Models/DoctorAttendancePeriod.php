<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One stay of a doctor on a visit day: from coming in to going out. */
class DoctorAttendancePeriod extends Model
{
    protected $fillable = ['arrived_at', 'left_at', 'ended_by_break'];

    protected $casts = [
        'arrived_at' => 'datetime',
        'left_at' => 'datetime',
        'ended_by_break' => 'boolean',
    ];
}
