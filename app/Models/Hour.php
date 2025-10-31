<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hour extends Model
{
    protected $fillable = [
        'day_id',
        'time',
        'max_allowed',
        'counter',
    ];

    protected $casts = [
        'time' => 'datetime:H:i:s',
    ];

    public function day()
    {
        return $this->belongsTo(Day::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}