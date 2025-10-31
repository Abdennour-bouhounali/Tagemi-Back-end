<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Specialty extends Model
{
    protected $fillable = [
        'name',
    ];

    // Other relationships
    public function eventSpecialties()
    {
        return $this->hasMany(EventSpecialty::class);
    }
}