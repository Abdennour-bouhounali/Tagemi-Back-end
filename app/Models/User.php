<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    public const ROLE_SUPER_ADMIN = 1;
    public const ROLE_SIMPLE_USER = 2;
    public const ROLE_SPECIAL_ADMIN = 3;
    public const ROLE_CHECK_ADMIN = 4;
    public const ROLE_RECIPIENT_ADMIN = 5;
    public const ROLE_DOCTOR = 6;
    public const ROLE_CONTENT_ADMIN = 7;
    // Runs the specialty queues like a check admin, but may only push patients down (إنزال)
    public const ROLE_PATIENT_MANAGER = 8;

    // Names usable in the `role:` route middleware
    public const ROLES = [
        'super_admin' => self::ROLE_SUPER_ADMIN,
        'simple_user' => self::ROLE_SIMPLE_USER,
        'special_admin' => self::ROLE_SPECIAL_ADMIN,
        'check_admin' => self::ROLE_CHECK_ADMIN,
        'recipient_admin' => self::ROLE_RECIPIENT_ADMIN,
        'doctor' => self::ROLE_DOCTOR,
        'content_admin' => self::ROLE_CONTENT_ADMIN,
        'patient_manager' => self::ROLE_PATIENT_MANAGER,
    ];

    // role_id and event_id are deliberately NOT fillable: they must only be
    // set explicitly by a super admin, never from request data.
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hasRole(int ...$roleIds): bool
    {
        return in_array((int) $this->role_id, $roleIds, true);
    }

    public function isSuperAdmin(): bool
    {
        return (int) $this->role_id === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Staff are bound to a single event through users.event_id; only the
     * super admin may act on any event.
     */
    public function canAccessEvent($eventId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $eventId !== null && $this->event_id !== null
            && (int) $this->event_id === (int) $eventId;
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    /** Set when this is a doctor's login account. */
    public function doctor()
    {
        return $this->hasOne(Doctor::class);
    }
}