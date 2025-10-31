<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $fillable = [
        'name',
        'date',
        'state', 
        'city',
        'type',
        'description',
        'is_active',
        'is_archived',
        'is_tawat',
        'special_admin_id',
        'recipient_admin_id',
        'check_admin_id',
    ];

    protected $casts = [
        'date' => 'date',
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
        'is_tawat' => 'boolean',
    ];

    public function specialAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'special_admin_id', 'id');
    }

    public function recipientAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_admin_id', 'id');
    }

    public function checkAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'check_admin_id', 'id');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function eventSpecialties(): HasMany
    {
        return $this->hasMany(EventSpecialty::class);
    }

    /**
     * Get all appointments for this event (FIXED: direct relationship)
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'event_id');
    }

    /**
     * Get archived appointments for this event
     */
    public function archivedAppointments(): HasMany
    {
        return $this->hasMany(AppointmentArchive::class, 'event_id');
    }
}