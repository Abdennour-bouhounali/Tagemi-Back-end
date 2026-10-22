<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Event;
use App\Services\QueueService;
use App\Support\VisitStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "See my turn" page opened from the public QR poster. The patient types
 * either their patient number or the phone number they booked with, and sees
 * their appointments and place in line (first name only, no other data).
 * One phone can belong to several patients (a parent booking for the family).
 */
class PublicTurnController extends Controller
{
    private const MAX_PATIENTS_PER_PHONE = 6;

    public function __construct(private QueueService $queues)
    {
    }

    public function show(Request $request)
    {
        $data = $request->validate([
            'event_id' => 'required|integer',
            'patient_id' => 'nullable|required_without:phone|integer|min:1',
            'phone' => 'nullable|required_without:patient_id|string|max:20',
        ]);

        $event = Event::where('is_archived', false)->find($data['event_id']);
        $appointments = $event ? $this->lookup($event, $data) : collect();

        if ($appointments->isEmpty()) {
            return response()->json([
                'message' => !empty($data['patient_id'])
                    ? 'لم نجد مريضاً بهذا الرقم في هذه الحملة. تأكد من رقم المريض.'
                    : 'لم نجد موعداً بهذا الرقم في هذه الحملة. تأكد من رقم الهاتف الذي حجزت به.',
            ], 404);
        }

        $patients = $appointments->groupBy('patient_id')
            ->take(self::MAX_PATIENTS_PER_PHONE)
            ->map(fn (Collection $rows) => [
                'first_name' => explode(' ', trim($rows->first()->full_name))[0],
                'patient_id' => $rows->first()->patient_id,
                'appointments' => $this->describe($rows),
            ])
            ->values();

        return response()->json([
            'event' => ['id' => $event->id, 'name' => $event->name],
            'today' => QueueService::today(),
            'patients' => $patients,
            'checked_at' => now(QueueService::TIMEZONE)->format('H:i'),
        ]);
    }

    private function lookup(Event $event, array $data): Collection
    {
        $query = Appointment::with(['eventSpecialty.specialty', 'day', 'hour'])->where('event_id', $event->id);

        if (!empty($data['patient_id'])) {
            return $query->where('patient_id', $data['patient_id'])->get();
        }

        $phone = self::phoneKey($data['phone']);
        if (strlen($phone) < 9) {
            return collect();
        }

        // Compare the last 9 digits so 0555…, +213 555… and spaced numbers match
        return $query->where(function ($q) use ($phone) {
            $q->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ?", [$phone])
                ->orWhereRaw("RIGHT(REGEXP_REPLACE(phone2, '[^0-9]', ''), 9) = ?", [$phone]);
        })->orderBy('patient_id')->get();
    }

    private function describe(Collection $rows): Collection
    {
        $today = QueueService::today();

        return $rows
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
    }

    /** Last 9 digits: 0555 12 34 56, +213 555 12 34 56 and 213555123456 all match. */
    private static function phoneKey(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    }
}
