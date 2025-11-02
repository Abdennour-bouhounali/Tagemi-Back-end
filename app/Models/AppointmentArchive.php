<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class AppointmentArchive extends Model
{
    use HasFactory;

    protected $table = 'appointments_archive';
    
    public $incrementing = false;

    protected $fillable = [
        'id',
        'event_id',
        'user_id',
        'event_specialty_id',
        'day_id',
        'hour_id',
        'doctor_id',
        'full_name',
        'birthday',
        'state',
        'city',
        'sex',
        'phone',
        'phone2',
        'diseases',
        'patient_id',
        'specialty_order',
        'position',
        'orderList',
        'comment',
        'status',
        'is_special',
        'archived_at',
        'archived_reason',
        'medical_documents', // NEW
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'birthday' => 'date',
        'is_special' => 'boolean',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'medical_documents' => 'array', // NEW
    ];

    // ... rest of the relationships and methods remain the same
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function eventSpecialty()
    {
        return $this->belongsTo(EventSpecialty::class);
    }

    public function day()
    {
        return $this->belongsTo(Day::class);
    }

    public function hour()
    {
        return $this->belongsTo(Hour::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function scopeForEvent($query, $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    public function scopeArchivedToday($query)
    {
        return $query->whereDate('archived_at', today());
    }

    public function scopeArchivedThisMonth($query)
    {
        return $query->whereMonth('archived_at', now()->month)
                    ->whereYear('archived_at', now()->year);
    }

    public function getAgeAttribute()
    {
        return Carbon::parse($this->birthday)->age;
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
}