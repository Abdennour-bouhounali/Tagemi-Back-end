<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** One change of an appointment (status or manual change): who did it, when, and what. */
class AppointmentLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['appointment_id', 'event_id', 'patient_id', 'user_id', 'action', 'details'];

    protected $casts = ['details' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Status from -> to, with the specialty and the staff action that caused it. */
    public static function statusChanged(Appointment $appointment, ?string $from): self
    {
        $action = Str::afterLast((string) request()->route()?->getActionName(), '\\');

        return self::record('status_changed', $appointment, [
            'from' => $from,
            'to' => $appointment->status,
            'specialty' => $appointment->eventSpecialty?->specialty?->name,
            'via' => AdminAction::LABELS[$action] ?? null,
        ]);
    }

    public static function record(string $action, Appointment $appointment, array $details = []): self
    {
        return self::create([
            'appointment_id' => $appointment->id,
            'event_id' => $appointment->event_id,
            'patient_id' => $appointment->patient_id,
            'user_id' => Auth::id(),
            'action' => $action,
            'details' => $details ?: null,
        ]);
    }
}
