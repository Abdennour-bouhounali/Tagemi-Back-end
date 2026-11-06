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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Visit day (super admin): doctors' arrival and departure, as many times as
 * needed in a day (a mid-day break). The first doctor of a specialty who
 * arrives opens its queue; when the last one present leaves, the queue closes
 * (patients already in it are still served) until a doctor comes back.
 * «استراحة» is a short absence: the doctor takes no patients until back, but
 * the queue stays open and their planned visits still count.
 * «غادر» only changes where new patients are sent: it is allowed while the
 * doctor is with a patient, who finishes that visit and the queue.
 */
class DoctorAttendanceController extends Controller
{
    public function __construct(private QueueService $queues)
    {
    }

    /**
     * Doctors scheduled today in each specialty of the event, with their
     * attendance. Before the visit day (no day today) the doctors of the next
     * visit day are listed for checking (is_today false: no arrival yet).
     */
    public function today($eventId)
    {
        $eventDays = fn () => Day::whereHas('eventSpecialty', fn ($q) => $q->where('event_id', $eventId));
        $today = QueueService::today();
        $date = $eventDays()->whereDate('day_date', $today)->exists()
            ? $today
            : ($eventDays()->whereDate('day_date', '>', $today)->min('day_date')
                ?? $eventDays()->max('day_date'));
        $date = $date ? substr((string) $date, 0, 10) : $today;

        $days = Day::with('intervals.doctor')
            ->whereHas('eventSpecialty', fn ($q) => $q->where('event_id', $eventId))
            ->whereDate('day_date', $date)
            ->get();

        $attendance = DoctorAttendance::with('periods')->whereIn('day_id', $days->pluck('id'))->get()
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
                    'present_since' => $this->clock($a?->presentSince()),
                    'periods' => $a?->periodClocks() ?? [],
                    'present' => (bool) $a?->isWorking(),
                    'on_break' => (bool) $a?->isOnBreak(),
                    'on_break_since' => $this->clock($a?->on_break_since),
                ];
            })->values(),
        ])->values();

        return ['date' => $date, 'is_today' => $date === $today, 'specialties' => $specialties];
    }

    /**
     * Opening a specialty with doctors not planned in the event form (the
     * training event, a replacement doctor): each one gets an interval today
     * from now to end_time, then arrives like a planned doctor (opens the
     * queue). Hours are not touched: patients already booked them.
     */
    public function add(Request $request, $eventSpecialtyId)
    {
        $data = $request->validate([
            'doctor_ids' => 'required|array|min:1|max:20',
            'doctor_ids.*' => 'integer|distinct|exists:doctors,id',
            'end_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'visit_minutes' => 'required|integer|min:5|max:120',
        ]);

        $eventSpecialty = EventSpecialty::findOrFail($eventSpecialtyId);
        $day = Day::where('event_specialty_id', $eventSpecialty->id)
            ->whereDate('day_date', QueueService::today())->first();
        if (!$day) {
            throw new RuleException('هذا التخصص غير مبرمج اليوم في الحدث', 422);
        }

        $now = now()->setTimezone(QueueService::TIMEZONE);
        $start = intdiv($now->hour * 60 + $now->minute, 5) * 5;
        [$h, $m] = array_map('intval', explode(':', $data['end_time']));
        $end = $h * 60 + $m;
        if ($end <= $start) {
            throw new RuleException('وقت نهاية العمل يجب أن يكون بعد الآن', 422);
        }

        $doctors = Doctor::whereIn('id', $data['doctor_ids'])->get();
        foreach ($doctors as $doctor) {
            if (!$doctor->is_active) {
                throw new RuleException("الطبيب {$doctor->full_name} غير مفعّل", 422);
            }
            $elsewhere = DoctorAttendance::where('doctor_id', $doctor->id)->where('day_id', '!=', $day->id)
                ->whereNotNull('arrived_at')->whereNull('left_at')
                ->whereHas('day', fn ($q) => $q->whereDate('day_date', QueueService::today()))
                ->exists();
            if ($elsewhere) {
                throw new RuleException("الطبيب {$doctor->full_name} حاضر في تخصص آخر اليوم، سجّل مغادرته منه أولاً", 409);
            }
        }

        $doctors->each(fn ($doctor) => $this->ensureAttendance($day, $doctor));
        DB::transaction(function () use ($doctors, $day, $start, $end, $data) {
            foreach ($doctors as $doctor) {
                if (!DoctorInterval::where('day_id', $day->id)->where('doctor_id', $doctor->id)->exists()) {
                    DoctorInterval::create([
                        'day_id' => $day->id,
                        'doctor_id' => $doctor->id,
                        'start_time' => sprintf('%02d:%02d:00', intdiv($start, 60), $start % 60),
                        'end_time' => sprintf('%02d:%02d:00', intdiv($end, 60), $end % 60),
                        'visit_minutes' => $data['visit_minutes'],
                        'extra_visits' => 0,
                        'visits_count' => intdiv($end - $start, $data['visit_minutes']),
                    ]);
                }
                $attendance = $this->attendance($day, $doctor);
                if (!$attendance->isWorking()) {
                    $attendance->recordArrival();
                }
            }
        });

        $names = $doctors->pluck('full_name')->join('، ');
        $message = $doctors->count() > 1 ? "أُضيف الأطباء {$names} وسُجّل وصولهم" : "أُضيف الطبيب {$names} وسُجّل وصوله";
        if (!$eventSpecialty->isOpenToday()) {
            $result = $this->queues->openSpecialty($eventSpecialty);
            $sent = $result['sent'] + $result['moved'];
            $message .= '، وفُتح طابور التخصص' . ($sent > 0 ? "، أُرسل إليه {$sent} زائر" : '');
            Log::info('Specialty opened with added doctors', ['event_specialty_id' => $eventSpecialty->id, 'doctor_ids' => $doctors->pluck('id'), 'by' => Auth::id()] + $result);
        } else {
            $message .= $this->sendWaiting($eventSpecialty);
        }

        return response()->json(['message' => $message, 'is_open' => true]);
    }

    public function arrive($eventSpecialtyId, Doctor $doctor)
    {
        $eventSpecialty = EventSpecialty::findOrFail($eventSpecialtyId);
        $day = $this->scheduledToday($eventSpecialty, $doctor);

        $this->ensureAttendance($day, $doctor);
        $fromBreak = DB::transaction(function () use ($day, $doctor) {
            $attendance = $this->attendance($day, $doctor);
            if ($attendance->isWorking()) {
                throw new RuleException("الطبيب {$doctor->full_name} مسجل حاضراً من قبل", 409);
            }
            $fromBreak = $attendance->isOnBreak();
            $attendance->recordArrival();

            return $fromBreak;
        });

        $message = $fromBreak ? "سُجّلت عودة الطبيب {$doctor->full_name} من الاستراحة" : "سُجّل وصول الطبيب {$doctor->full_name}";
        if (!$eventSpecialty->isOpenToday()) {
            $result = $this->queues->openSpecialty($eventSpecialty);
            $sent = $result['sent'] + $result['moved'];
            $message .= '، وفُتح طابور التخصص' . ($sent > 0 ? "، أُرسل إليه {$sent} زائر" : '');
            Log::info('Specialty opened by doctor arrival', ['event_specialty_id' => $eventSpecialty->id, 'doctor_id' => $doctor->id, 'by' => Auth::id()] + $result);
        } else {
            $message .= $this->sendWaiting($eventSpecialty);
        }

        return response()->json(['message' => $message, 'is_open' => true]);
    }

    /** Out for a while: no patients until back, the queue and the capacity don't change. */
    public function takeBreak($eventSpecialtyId, Doctor $doctor)
    {
        $eventSpecialty = EventSpecialty::findOrFail($eventSpecialtyId);
        $day = $this->scheduledToday($eventSpecialty, $doctor);

        DB::transaction(function () use ($day, $doctor) {
            $attendance = $this->attendance($day, $doctor);
            if (!$attendance->isWorking()) {
                throw new RuleException("الطبيب {$doctor->full_name} غير مسجل حاضراً", 409);
            }
            $attendance->recordBreak();
        });

        return response()->json(['message' => "سُجّلت استراحة الطبيب {$doctor->full_name}", 'is_open' => $eventSpecialty->isOpenToday()]);
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
            $attendance->recordDeparture();

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
        } elseif ($open) {
            // Their finished visits stop counting against the capacity
            $message .= $this->sendWaiting($eventSpecialty);
        }

        return response()->json(['message' => $message, 'is_open' => $open]);
    }

    /** Already open, and it may now have room: the end of the staff message. */
    private function sendWaiting(EventSpecialty $eventSpecialty): string
    {
        $sent = $this->queues->sendWaiting($eventSpecialty->id);
        if ($sent > 0) {
            Log::info('Waiting patients sent to open specialty', ['event_specialty_id' => $eventSpecialty->id, 'sent' => $sent, 'by' => Auth::id()]);
        }

        return $sent > 0 ? "، أُرسل إلى الطابور {$sent} زائر" : '';
    }

    /** Every day the doctor was scheduled, newest first, with what happened. */
    public function history(Doctor $doctor)
    {
        $intervals = DoctorInterval::with(['day.eventSpecialty.event', 'day.eventSpecialty.specialty'])
            ->where('doctor_id', $doctor->id)
            ->get()
            ->groupBy('day_id');

        $attendance = DoctorAttendance::with('periods')->where('doctor_id', $doctor->id)
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
                'periods' => $a?->periodClocks() ?? [],
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

    /**
     * The day's row exists before the transaction that locks it: locking a
     * missing row takes a gap lock, and two first arrivals at once deadlock.
     * Outside any transaction, or insertOrIgnore's shared lock stays held and
     * deadlocks with the lock that follows.
     */
    private function ensureAttendance(Day $day, Doctor $doctor): void
    {
        DoctorAttendance::insertOrIgnore([
            'day_id' => $day->id, 'doctor_id' => $doctor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
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
