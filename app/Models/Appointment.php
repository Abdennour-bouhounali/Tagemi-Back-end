<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'user_id',
        'event_specialty_id',
        'day_id',
        'hour_id',
        'doctor_id',
        'event_id',
        'full_name',
        'birthday',
        'state',           // NEW - replaces residence
        'city',            // NEW
        'diseases',
        'phone',
        'phone2',
        'sex',
        'patient_id',
        'specialty_order',
        'position',
        'orderList',
        'comment',
        'status',
        'is_special',
        'archived_at',
        'archived_reason',
    ];

    protected $casts = [
        'birthday' => 'date',
        'is_special' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
        public function archivedAppointments()
    {
        return $this->hasMany(AppointmentArchive::class, 'event_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

        public function eventSpecialty()
    {
        return $this->belongsTo(EventSpecialty::class);
    }
    public function specialty()
    {
        return $this->belongsTo(Specialty::class, 'event_specialty_id', 'id')
            ->join('event_specialties', 'specialties.id', '=', 'event_specialties.specialty_id');
    }

    public function day()
    {
        return $this->belongsTo(Day::class);
    }

    public function hour()
    {
        return $this->belongsTo(Hour::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    // Helper to get specialty name easily
    public function getSpecialtyAttribute()
    {
        return $this->eventSpecialty?->specialty;
    }
}