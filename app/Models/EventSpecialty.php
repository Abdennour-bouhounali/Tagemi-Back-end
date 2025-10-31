<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventSpecialty extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'specialty_id',
        'is_saturated',
        'max_number',
        'start_time',
        'addition_capacity',
        'flag',
        'is_active',
    ];

    protected $casts = [
        'is_saturated' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_saturated' => false,
        'is_active' => true,
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    public function days()
    {
        return $this->hasMany(Day::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function doctors()
    {
        return $this->belongsToMany(Doctor::class, 'event_specialty_doctors');
    }

}