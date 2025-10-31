<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'is_active',
        'created_at',
        'updated_at',
    ];

    public function eventSpecialties()
    {
        return $this->belongsToMany(EventSpecialty::class, 'event_specialty_doctors');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
