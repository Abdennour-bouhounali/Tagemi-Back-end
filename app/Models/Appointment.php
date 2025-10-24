<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;
    protected $fillable = [
       'user_id',
       'event_id',
        'name',
        'lastName',       // New field
        'patient_id',
        'specialty_id',
        'specialty_order',
        'time',
        'status',
        'position',
        'orderList',
        'birthday',        // New field
        'residence',       // New field
        'diseases',        // New field
        'phone',           // New field
        'comment',
        'sex'              // New field
    ];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    // public function waitingLists()
    // {
    //     return $this->hasMany(WaitingList::class);
    // }
}
