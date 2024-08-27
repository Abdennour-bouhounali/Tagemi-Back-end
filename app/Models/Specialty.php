<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Specialty extends Model
{
    use HasFactory;
    protected $fillable = ['name','specialty_time','duration','Max_Number','Addition_Capacitif','Flag'];

    public function doctors()
    {
        return $this->hasMany(Doctor::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function waitingLists()
    {
        return $this->hasMany(WaitingList::class);
    }
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
