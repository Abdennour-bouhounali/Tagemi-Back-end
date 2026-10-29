<?php

namespace App\Http\Controllers;

use App\Exceptions\RuleException;
use App\Models\Day;
use App\Models\Doctor;
use App\Models\DoctorAttendance;
use App\Models\DoctorInterval;
use App\Models\EventSpecialty;
use App\Services\QueueService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Visit day (super admin): doctors' arrival and departure. The first doctor
 * of a specialty who arrives opens its queue; when the last one present
 * leaves, the queue closes (patients already in it are still served).
 */
class DoctorAttendanceController extends Controller
{
    public function __construct(private QueueService $queues)
    {
    }

    /** Doctors scheduled today in each specialty of the event, with their attendance. */
    public function today($eventId)
    {
        $days = Day::with('intervals.doctor')
            ->whereHas('eventSpecialty', fn ($q) => $q->where('event_id', $eventId))
            ->whereDate('day_date', QueueService::today())
            ->get();

        $attendance = DoctorAttendance::whereIn('day_id', $days->pluck('id'))->get()
            ->keyBy(fn ($a) => $a->day_id . '-' . $a->doctor_id);

        $specialties = $days->map(fn ($day) => [
            'event_specialty_id' => $day->event_specialty_id,
            'doctors' => $day->intervals->groupBy('doctor_id')->map(function ($intervals, $doctorId) use ($day, $attendance) {
                $a = $attendance[$day->id . '-' . $doctorId] ?? null;

                return [
                    'doctor_id' => (int) $doctorId,
                    'full_name' => $intervals->first()->doctor?->full_name,
                    'intervals' => $this->intervalLabels($intervals),
                    'planned_visits' => (int) $intervals->sum('visits_count'),
                    'arrived_at' => $this->clock($a?->arrived_at),
                    'left_at' => $this->clock($a?->left_at),
                    'present' => (bool) $a?->isPresent(),
                ];
            })->values(),
        ])->values();

        return ['specialties' => $specialties];
    }

    public function arrive($eventSpecialtyId, Doctor $doctor)
    {
        $eventSpecialty = EventSpecialty::findOrFail($eventSpecialtyId);
        $day = $this->scheduledToday($eventSpecialty, $doctor);

        DB::transaction(function () use ($day, $doctor) {
            $attendance = $this->attendance($day, $doctor);
            if ($attendance->isPresent()) {
                throw new RuleException("الطبيب {$doctor->full_name} مسجل حاضراً من قبل", 409);
            }
            // Back after leaving: keep the first arrival time
            $attendance->arrived_at ??= now();
            $attendance->left_at = null;
            $attendance->recorded_by = Auth::id();
            $attendance->save();
        });

        $message = "سُجّل وصول الطبيب {$doctor->full_name}";
        if (!$eventSpecialty->isOpenToday()) {
            $result = $this->queues->openSpecialty($eventSpecialty);
            $sent = $result['sent'] + $result['moved'];
            $message .= '، وفُتح طابور التخصص' . ($sent > 0 ? "، أُرسل إليه {$sent} مريض" : '');
            Log::info('Specialty opened by doctor arrival', ['event_specialty_id' => $eventSpecialty->id, 'doctor_id' => $doctor->id, 'by' => Auth::id()] + $result);
        }

        return response()->json(['message' => $message, 'is_open' => true]);
    }

    public function leave($eventSpecialtyId, Doctor $doctor)
    {
        $eventSpecialty = EventSpecialty::findOrFail($eventSpecialtyId);
        $day = $this->scheduledToday($eventSpecialty, $doctor);

        $othersPresent = DB::transaction(function () use ($day, $doctor) {
            $attendance = $this->attendance($day, $doctor);
            if (!$attendance->isPresent()) {
                throw new RuleException("الطبيب {$doctor->full_name} غير مسجل حاضراً", 409);
            }
            $attendance->left_at = now();
            $attendance->recorded_by = Auth::id();
            $attendance->save();

            return DoctorAttendance::where('day_id', $day->id)
                ->whereNotNull('arrived_at')->whereNull('left_at')
                ->lockForUpdate()->exists();
        });

        $message = "سُجّلت مغادرة الطبيب {$doctor->full_name}";
        $open = $eventSpecialty->isOpenToday();
        if (!$othersPresent && $open) {
            $this->queues->closeSpecialty($eventSpecialty);
            $open = false;
            $message .= '، وأُغلق طابور التخصص (يُكمَل فحص من فيه)';
            Log::info('Specialty closed by last doctor leaving', ['event_specialty_id' => $eventSpecialty->id, 'doctor_id' => $doctor->id, 'by' => Auth::id()]);
        }

        return response()->json(['message' => $message, 'is_open' => $open]);
    }

    /** Every day the doctor was scheduled, newest first, with what happened. */
    public function history(Doctor $doctor)
    {
        $intervals = DoctorInterval::with(['day.eventSpecialty.event', 'day.eventSpecialty.specialty'])
            ->where('doctor_id', $doctor->id)
            ->get()
            ->groupBy('day_id');

        $attendance = DoctorAttendance::where('doctor_id', $doctor->id)
            ->whereIn('day_id', $intervals->keys())->get()->keyBy('day_id');

        $days = $intervals->map(function ($dayIntervals, $dayId) use ($attendance) {
            $day = $dayIntervals->first()->day;
            $a = $attendance[$dayId] ?? null;

            return [
                'day_date' => date('Y-m-d', strtotime($day->day_date)),
                'event_id' => $day->eventSpecialty?->event?->id,
                'event_name' => $day->eventSpecialty?->event?->name,
                'event_archived' => (bool) $day->eventSpecialty?->event?->is_archived,
                'specialty' => $day->eventSpecialty?->specialty?->name,
                'intervals' => $this->intervalLabels($dayIntervals),
                'planned_visits' => (int) $dayIntervals->sum('visits_count'),
                'arrived_at' => $this->clock($a?->arrived_at),
                'left_at' => $this->clock($a?->left_at),
                'note' => $a?->note,
            ];
        })->sortByDesc('day_date')->values();

        return [
            'doctor' => $doctor->only(['id', 'full_name']),
            'summary' => [
                'events' => $days->pluck('event_id')->filter()->unique()->count(),
                'days' => $days->count(),
                'days_present' => $days->whereNotNull('arrived_at')->count(),
                'planned_visits' => $days->sum('planned_visits'),
            ],
            'days' => $days,
        ];
    }

    /** Today's day of the specialty where the doctor has intervals; refused otherwise. */
    private function scheduledToday(EventSpecialty $eventSpecialty, Doctor $doctor): Day
    {
        $day = Day::where('event_specialty_id', $eventSpecialty->id)
            ->whereDate('day_date', QueueService::today())
            ->whereHas('intervals', fn ($q) => $q->where('doctor_id', $doctor->id))
            ->first();

        if (!$day) {
            throw new RuleException("الطبيب {$doctor->full_name} غير مبرمج في هذا التخصص اليوم", 422);
        }

        return $day;
    }

    private function attendance(Day $day, Doctor $doctor): DoctorAttendance
    {
        return DoctorAttendance::where('day_id', $day->id)->where('doctor_id', $doctor->id)->lockForUpdate()->first()
            ?? new DoctorAttendance(['day_id' => $day->id, 'doctor_id' => $doctor->id]);
    }

    private function intervalLabels($intervals): array
    {
        return $intervals->sortBy('start_time')
            ->map(fn ($iv) => substr($iv->start_time, 0, 5) . '–' . substr($iv->end_time, 0, 5))
            ->values()->all();
    }

    private function clock(?CarbonInterface $time): ?string
    {
        return $time?->copy()->setTimezone(QueueService::TIMEZONE)->format('H:i');
    }
}
