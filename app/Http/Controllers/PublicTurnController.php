<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Event;
use App\Services\QueueService;
use App\Support\VisitStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "See my turn" page opened from the public QR poster. The patient proves who
 * they are with their patient number AND their phone number, and only sees
 * their own appointments and place in line (first name only, no other data).
 */
class PublicTurnController extends Controller
{
    public function __construct(private QueueService $queues)
    {
    }

    public function show(Request $request)
    {
        $data = $request->validate([
            'event_id' => 'required|integer',
            'patient_id' => 'required|integer|min:1',
            'phone' => 'required|string|max:20',
        ]);

        $event = Event::where('is_archived', false)->find($data['event_id']);
        $appointments = $event
            ? Appointment::with(['eventSpecialty.specialty', 'day', 'hour'])
                ->where('event_id', $event->id)
                ->where('patient_id', $data['patient_id'])
                ->get()
            : collect();

        $phone = self::phoneKey($data['phone']);
        $owner = $appointments->first();
        $matches = $owner && $phone !== '' && in_array($phone, [self::phoneKey($owner->phone), self::phoneKey($owner->phone2)], true);

        // Same answer for "no such patient" and "wrong phone": nothing to probe
        if (!$matches) {
            return response()->json(['message' => 'لم نجد موعداً بهذه المعلومات. تأكد من رقم المريض ورقم الهاتف.'], 404);
        }

        $today = QueueService::today();
        $rows = $appointments
            ->sortBy(fn ($a) => [$a->day?->day_date, $a->hour?->time])
            ->map(function (Appointment $a) use ($today) {
                $date = $a->day ? Carbon::parse($a->day->day_date)->toDateString() : null;
                $group = VisitStatistics::groupOf($a->status);
                $position = $date === $today && $group === 'in_queue' ? $this->queues->positionOf($a) : null;

                return [
                    'specialty' => $a->eventSpecialty?->specialty?->name,
                    'date' => $date,
                    'time' => $a->hour?->time ? substr($a->hour->time, 0, 5) : null,
                    'is_today' => $date === $today,
                    'state' => $group,
                    // Dilatation: sent (go to the dilatation room) vs already there
                    'dilatation_step' => $group === 'dilatation' ? ($a->status === 'DilatationSent' ? 'sent' : 'in_room') : null,
                    'is_priority' => (bool) $a->is_priority,
                    'position' => $position,
                    'people_before' => $position ? $position - 1 : null,
                ];
            })
            ->values();

        return response()->json([
            'first_name' => explode(' ', trim($owner->full_name))[0],
            'patient_id' => $owner->patient_id,
            'event' => ['id' => $event->id, 'name' => $event->name],
            'today' => $today,
            'appointments' => $rows,
            'checked_at' => now(QueueService::TIMEZONE)->format('H:i'),
        ]);
    }

    /** Last 9 digits: 0555 12 34 56, +213 555 12 34 56 and 213555123456 all match. */
    private static function phoneKey(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    }
}
