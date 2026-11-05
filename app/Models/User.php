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
    // مسؤول التنظيم: sees every screen of their event, can change nothing
    public const ROLE_ORGANIZER = 9;

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
        'organizer' => self::ROLE_ORGANIZER,
    ];

    // role_id and event_id are deliberately NOT fillable: they must only be
    // set explicitly by a super admin, never from request data.
    protected $fillable = [
        'name',
        'first_name',
        'family_name',
        'email',
        'password',
        'phone'
    ];

    // Arabic letters (with tashkeel and tatweel), spaces, and inner '-' ; no Latin, no digits
    public const ARABIC_NAME_REGEX = '/^[\x{0621}-\x{063A}\x{0640}-\x{0652}\x{0670}-\x{06D3}]+(?:[\s\-]+[\x{0621}-\x{063A}\x{0640}-\x{0652}\x{0670}-\x{06D3}]+)*$/u';

    // The app asks the account for its Arabic first and family name when true
    protected $appends = ['needs_name_update'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'can_exceed_specialty_limit' => 'boolean',
        ];
    }

    public static function isArabicName(?string $value): bool
    {
        $value = trim((string) $value);

        return mb_strlen($value) >= 2 && preg_match(self::ARABIC_NAME_REGEX, $value) === 1;
    }

    /**
     * [first, family] from an Arabic full name of two words or more, else
     * [null, null]. Compound first names (عبد الله، أبو بكر) keep both words.
     */
    public static function splitArabicName(string $fullName): array
    {
        $words = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2 || !self::isArabicName(implode(' ', $words))) {
            return [null, null];
        }

        $firstWords = count($words) > 2 && in_array($words[0], ['عبد', 'أبو', 'ابو'], true) ? 2 : 1;

        return [implode(' ', array_slice($words, 0, $firstWords)), implode(' ', array_slice($words, $firstWords))];
    }

    public function getNeedsNameUpdateAttribute(): bool
    {
        return !self::isArabicName($this->first_name) || !self::isArabicName($this->family_name);
    }

    /** Sets both parts and the joined `name` (not saved). */
    public function setArabicName(string $firstName, string $familyName): static
    {
        $first = preg_replace('/\s+/u', ' ', trim($firstName));
        $family = preg_replace('/\s+/u', ' ', trim($familyName));

        return $this->fill(['first_name' => $first, 'family_name' => $family, 'name' => $first.' '.$family]);
    }

    /** Validation rules for an Arabic first + family name. */
    public static function nameRules(bool $required = true): array
    {
        $base = ['string', 'min:2', 'max:100', 'regex:'.self::ARABIC_NAME_REGEX];
        if ($required) {
            return ['first_name' => ['required', ...$base], 'family_name' => ['required', ...$base]];
        }

        // Optional, but always both together
        return [
            'first_name' => ['nullable', 'required_with:family_name', ...$base],
            'family_name' => ['nullable', 'required_with:first_name', ...$base],
        ];
    }

    public const NAME_MESSAGES = [
        'first_name.required' => 'الاسم مطلوب',
        'first_name.required_with' => 'الاسم مطلوب',
        'family_name.required_with' => 'اللقب مطلوب',
        'family_name.required' => 'اللقب مطلوب',
        'first_name.min' => 'الاسم قصير جداً',
        'family_name.min' => 'اللقب قصير جداً',
        'first_name.max' => 'الاسم طويل جداً',
        'family_name.max' => 'اللقب طويل جداً',
        'first_name.regex' => 'اكتب الاسم بالحروف العربية',
        'family_name.regex' => 'اكتب اللقب بالحروف العربية',
    ];

    public function hasRole(int ...$roleIds): bool
    {
        return in_array((int) $this->role_id, $roleIds, true);
    }

    /** Super admin, or an account the super admin allowed to book past the event's specialty limit. */
    public function canExceedSpecialtyLimit(): bool
    {
        return $this->isSuperAdmin() || (bool) $this->can_exceed_specialty_limit;
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