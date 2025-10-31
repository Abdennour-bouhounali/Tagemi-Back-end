<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Day extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_specialty_id',
        'day_date',
        'number_per_hour',  // NEW
    ];

    protected $casts = [
        'number_per_hour' => 'integer',
    ];

    protected $attributes = [
        'number_per_hour' => 6,  // Default value
    ];

    public function eventSpecialty()
    {
        return $this->belongsTo(EventSpecialty::class);
    }

    public function hours()
    {
        return $this->hasMany(Hour::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
