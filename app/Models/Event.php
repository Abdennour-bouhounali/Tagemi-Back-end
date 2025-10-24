<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;
    protected $fillable = [
        'name', 'date', 'place', 'description', 'is_current', 'is_archived'
    ];

    public function appointment()
    {
        return $this->hasMany(Appointment::class);
    }

}
