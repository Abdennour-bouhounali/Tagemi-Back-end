<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentArchive extends Model
{
    use HasFactory;

    protected $table = 'appointments_archive';

    protected $fillable = [
        'user_id', 'event_id', 'name', 'lastName', 'patient_id',
        'specialty_id', 'specialty_order', 'time', 'status', 'position',
        'orderList', 'birthday', 'residence', 'diseases', 'phone', 'comment', 'sex'
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
