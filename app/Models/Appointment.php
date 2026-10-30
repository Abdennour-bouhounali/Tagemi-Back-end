<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    // Medical documents live on the private disk (storage/app) and are only
    // served through the authorized documents endpoint. Files uploaded before
    // this change may still be on the 'public' disk (see DocumentController).
    public const DOCUMENTS_DISK = 'local';

    protected static function booted(): void
    {
        // Kept in sync with the name/birthday; backs the unique index that
        // stops the same person being booked twice for one specialty.
        static::saving(function (Appointment $appointment) {
            if ($appointment->isDirty(['full_name', 'birthday']) || !$appointment->identity_key) {
                $appointment->identity_key = self::identityKey($appointment->full_name, $appointment->birthday);
            }
        });
    }

    /**
     * Normalized "who is this person" key: Arabic spelling variants, diacritics,
     * tatweel, extra spaces and letter case don't make a different person.
     */
    public static function identityKey($fullName, $birthday): string
    {
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', (string) $fullName);
        $name = strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
        $date = $birthday ? \Illuminate\Support\Carbon::parse($birthday)->format('Y-m-d') : '';

        return sha1($name . '|' . $date);
    }

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
        'is_test',
        'is_reminded', // NEW
        'reminded_at', // NEW
        'is_evaluated',      // Make sure it's is_evaluated, not is_evaluation
        'evaluated_at',
        'archived_at',
        'archived_reason',
        'medical_documents',
        'data_consent_at',
    ];

    protected $casts = [
        'birthday' => 'date',
        'is_special' => 'boolean',
        'is_priority' => 'boolean',
        'dilatation_sent_at' => 'datetime',
        'dilatation_started_at' => 'datetime',
        'is_test' => 'boolean',
        'is_reminded' => 'boolean', // NEW
        'reminded_at' => 'datetime', // NEW
        'is_evaluated' => 'boolean',      // <--- NEW
        'evaluated_at' => 'datetime', // <--- NEW
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

        return array_map(function ($index) {
            return url("/api/appointments/{$this->id}/documents/{$index}");
        }, array_keys(array_values($this->medical_documents)));
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

    /**
     * Scope to get reminded appointments
     */
    public function scopeReminded($query)
    {
        return $query->where('is_reminded', true);
    }

    /**
     * Scope to get not reminded appointments
     */
    public function scopeNotReminded($query)
    {
        return $query->where('is_reminded', false);
    }

        /**
     * Scope to get reminded appointments
     */
    public function scopeEvaluated($query)
    {
        return $query->where('is_evaluated', true);
    }

    /**
     * Scope to get not reminded appointments
     */
    public function scopeNotEvaluated($query)
    {
        return $query->where('is_evaluated', false);
    }
}