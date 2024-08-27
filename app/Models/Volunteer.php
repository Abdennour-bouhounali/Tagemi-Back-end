<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Volunteer extends Model
{
    use HasFactory;
    protected $fillable = [
        'full_name',
        'date_of_birth',
        'gender',
        'phone_number',
        'email_address',
        'city',
        'state',
        'relevant_skills',
        'previous_volunteering_experience',
        'professional_background',
        'areas_of_interest',
        'preferred_types_of_activities',
        'reasons_for_volunteering'
    ];
}

