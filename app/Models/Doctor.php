<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    use HasFactory;

    public const SECTORS = ['public', 'private', 'both'];

    public const SEXES = ['male', 'female'];

    protected $fillable = [
        'first_name',
        'family_name',
        'sex',
        'phone',
        'wilaya_code',
        'specialty_id',
        'sector',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->family_name);
    }

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    /** Login account (role doctor), created with the doctor. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function intervals()
    {
        return $this->hasMany(DoctorInterval::class);
    }

    public function eventSpecialties()
    {
        return $this->belongsToMany(EventSpecialty::class, 'event_specialty_doctors');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
