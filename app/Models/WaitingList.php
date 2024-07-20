<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaitingList extends Model
{
    use HasFactory;
    protected $fillable = ['specialty_id', 'patient_id', 'position','name'];

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    // public function appointment()
    // {
    //     return $this->belongsTo(Appointment::class, 'patient_id');
    // }
}
