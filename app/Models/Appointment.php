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
        'state',
        'city',
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
        'is_test', // NEW
        'archived_at',
        'archived_reason',
        'medical_documents',
    ];

    protected $casts = [
        'birthday' => 'date',
        'is_special' => 'boolean',
        'is_test' => 'boolean', // NEW
        'archived_at' => 'datetime',
        'medical_documents' => 'array',
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

    /**
     * Get full URLs for medical documents
     */
    public function getMedicalDocumentUrlsAttribute()
    {
        if (!$this->medical_documents) {
            return [];
        }

        return array_map(function($path) {
            return asset('storage/' . $path);
        }, $this->medical_documents);
    }

     /**
     * Scope to exclude test appointments
     */
    public function scopeRealOnly($query)
    {
        return $query->where('is_test', false);
    }

    /**
     * Scope to get only test appointments
     */
    public function scopeTestOnly($query)
    {
        return $query->where('is_test', true);
    }
}