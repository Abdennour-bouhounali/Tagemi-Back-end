<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\QueueService;
use Illuminate\Database\Eloquent\Model;

class EventSpecialty extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'specialty_id',
        'description', 
        'is_saturated',
        'max_number',
        'start_time',
        'addition_capacity',
        'flag',
        'is_active',
        'has_dilatation',
        'opened_on',
    ];

    protected $casts = [
        'is_saturated' => 'boolean',
        'is_active' => 'boolean',
        'has_dilatation' => 'boolean',
        'opened_on' => 'date:Y-m-d',
    ];

    // Visit day: open = the super admin opened its queue today (doctor present)
    protected $appends = ['queue_open'];

    protected $attributes = [
        'is_saturated' => false,
        'is_active' => true,
    ];

    public function isOpenToday(): bool
    {
        return $this->opened_on?->toDateString() === QueueService::today();
    }

    public function getQueueOpenAttribute(): bool
    {
        return $this->isOpenToday();
    }

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