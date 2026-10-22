<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Event;
use App\Models\EventSpecialty;
use App\Models\Specialty;
use App\Services\QueueService;
use App\Support\VisitStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Specialty queues on visit day. Roles and event scoping are enforced by the
 * route middleware; the queue rules themselves live in QueueService.
 */
class WaitingListController extends Controller
{
    public function __construct(private QueueService $queues)
    {
    }

    /** "Passed" (إجتاز): examination done, the patient moves to their next specialty. */
    public function Completed($id)
    {
        $appointment = Appointment::with('eventSpecialty.specialty')->findOrFail($id);
        $next = $this->queues->complete($appointment);

        $this->audit('completed', $appointment);

        if ($next) {
            $next->load('eventSpecialty.specialty');
            return response()->json([
                'message' => 'تم الفحص، انتقل المريض إلى التخصص التالي',
                'next_specialty' => $next->eventSpecialty->specialty->name ?? '',
                'next_appointment' => $next,
            ]);
        }

        return response()->json(['message' => 'أنهى المريض كل تخصصاته لهذا اليوم']);
    }

    /** Special case (priority for the whole day). */
    public function Special($id)
    {
        $appointment = $this->queues->markSpecial(Appointment::findOrFail($id));
        $this->audit('special', $appointment);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديد المريض كحالة خاصة (أولوية)',
            'appointment' => $appointment,
        ]);
    }

    /** Specialty officer (تصحيح النظر): send the patient to the dilatation room. */
    public function Dilatation($id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->queues->sendToDilatation($appointment);
        $this->audit('dilatation_sent', $appointment);

        return response()->json(['message' => 'أُرسل المريض إلى قاعة التمدد']);
    }

    /** Dilatation officer: the patient arrived in the dilatation room. */
    public function DilatationArrived($id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->queues->receiveInDilatation($appointment);
        $this->audit('dilatation_received', $appointment);

        return response()->json(['message' => 'تم تسجيل وصول المريض إلى التمدد']);
    }

    /** Dilatation officer: done, back to the specialty with priority. */
    public function FinishedDilatation($id)
    {
        $appointment = Appointment::with('eventSpecialty.specialty')->findOrFail($id);
        $this->queues->finishDilatation($appointment);
        $this->audit('dilatation_finished', $appointment);

        return response()->json([
            'message' => 'عاد المريض إلى ' . ($appointment->eventSpecialty->specialty->name ?? 'التخصص') . ' (أولوية)',
            'position' => $this->queues->positionOf($appointment->fresh()),
        ]);
    }

    /**
     * Dilatation room screen: patients sent today by this specialty, split into
     * "on the way" and "in dilatation" (with the time they started).
     */
    public function dilatationRoom($eventSpecialtyId)
    {
        $eventSpecialty = EventSpecialty::with(['specialty', 'event:id,name'])->findOrFail($eventSpecialtyId);
        if (!$eventSpecialty->has_dilatation) {
            return response()->json(['message' => 'هذا التخصص ليس له قاعة تمدد'], 404);
        }

        $patients = $this->queues->dilatationList($eventSpecialty->id)
            ->map(fn ($a) => $a->only(['id', 'patient_id', 'full_name', 'sex', 'status', 'is_special', 'dilatation_sent_at', 'dilatation_started_at']));

        return response()->json([
            'specialty' => $eventSpecialty->specialty->name ?? '',
            'event' => $eventSpecialty->event,
            'coming' => $patients->where('status', 'DilatationSent')->values(),
            'in_room' => $patients->where('status', 'Dilatation')->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Push down (إنزال) one patient. */
    public function Commingsoon($id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->pushDownAppointments(collect([$appointment]));

        return response()->json(['message' => 'تم إنزال المريض ' . QueueService::PUSH_DOWN_PLACES . ' مراتب']);
    }

    /** Push down several patients of the same event at once. */
    public function pushDownMany(Request $request)
    {
        $data = $request->validate([
            'appointment_ids' => 'required|array|min:1|max:100',
            'appointment_ids.*' => 'integer|distinct',
        ]);

        $appointments = Appointment::whereIn('id', $data['appointment_ids'])->get();
        $user = $request->user();

        if ($appointments->count() !== count($data['appointment_ids'])
            || $appointments->contains(fn ($a) => !$user->canAccessEvent($a->event_id))) {
            return response()->json(['message' => 'You are not authorized to access this event.'], 403);
        }

        $this->pushDownAppointments($appointments);

        return response()->json([
            'message' => 'تم إنزال ' . $appointments->count() . ' مريض ' . QueueService::PUSH_DOWN_PLACES . ' مراتب',
        ]);
    }

    /** Open specialties today that the patient has not booked, for "send to specialty". */
    public function referralOptions($id)
    {
        $appointment = Appointment::findOrFail($id);
        $booked = Appointment::where('event_id', $appointment->event_id)
            ->where('patient_id', $appointment->patient_id)
            ->pluck('event_specialty_id');

        $options = EventSpecialty::with('specialty')
            ->where('event_id', $appointment->event_id)
            ->where('is_active', true)
            ->whereNotIn('id', $booked)
            ->whereHas('days', fn ($q) => $q->whereDate('day_date', QueueService::today()))
            ->get()
            ->map(fn ($es) => [
                'event_specialty_id' => $es->id,
                'name' => $es->specialty->name ?? '',
                'queue_length' => $this->queues->queue($es->id)->count(),
            ])
            ->values();

        return response()->json(['specialties' => $options]);
    }

    /** "Send to specialty": the patient leaves this queue and joins the other one in 3rd place. */
    public function refer(Request $request, $id)
    {
        $data = $request->validate(['event_specialty_id' => 'required|integer|exists:event_specialties,id']);
        $current = Appointment::findOrFail($id);
        $target = EventSpecialty::with('specialty')->findOrFail($data['event_specialty_id']);

        $new = $this->queues->refer($current, $target);
        $this->audit('referred', $current, ['to_appointment' => $new->id]);

        return response()->json([
            'message' => 'تم إرسال المريض إلى ' . ($target->specialty->name ?? 'التخصص الجديد'),
            'appointment' => $new,
            'position' => $this->queues->positionOf($new),
        ]);
    }

    /** Open / close a specialty for referrals and transfers (does not affect existing patients). */
    public function toggleOpen($eventSpecialtyId)
    {
        $eventSpecialty = EventSpecialty::findOrFail($eventSpecialtyId);
        $eventSpecialty->is_active = !$eventSpecialty->is_active;
        $eventSpecialty->save();

        return response()->json([
            'message' => $eventSpecialty->is_active ? 'التخصص مفتوح لاستقبال المرضى المحالين' : 'التخصص مغلق أمام الإحالات',
            'is_open' => $eventSpecialty->is_active,
        ]);
    }

    /**
     * Officer's home: every specialty of the event with today's live counts,
     * plus the dilatation room of specialties that have one.
     */
    public function stations($eventId)
    {
        $event = Event::findOrFail($eventId);
        $counts = QueueService::scopeToday(Appointment::query())
            ->where('event_id', $event->id)
            ->selectRaw('event_specialty_id, status, COUNT(*) as c')
            ->groupBy('event_specialty_id', 'status')
            ->get()
            ->groupBy('event_specialty_id');

        $stations = EventSpecialty::with('specialty')
            ->where('event_id', $event->id)
            ->get()
            ->map(function (EventSpecialty $es) use ($counts) {
                $byStatus = ($counts->get($es->id) ?? collect())->pluck('c', 'status');
                $sum = fn (array $statuses) => (int) collect($statuses)->sum(fn ($s) => $byStatus->get($s, 0));

                return [
                    'event_specialty_id' => $es->id,
                    'specialty_id' => $es->specialty_id,
                    'name' => $es->specialty->name ?? '',
                    'is_open' => (bool) $es->is_active,
                    'has_dilatation' => (bool) $es->has_dilatation,
                    'in_queue' => $sum(QueueService::QUEUE_STATUSES),
                    'completed' => $sum(['Completed']),
                    'dilatation_coming' => $sum(['DilatationSent']),
                    'dilatation_in_room' => $sum(['Dilatation']),
                ];
            })
            ->sortBy('name')
            ->values();

        return response()->json([
            'event' => ['id' => $event->id, 'name' => $event->name],
            'today' => QueueService::today(),
            'stations' => $stations,
        ]);
    }

    /**
     * Today's list for one specialty officer: patients in the queue (in order)
     * and in dilatation. Earlier or later days are not shown.
     */
    public function GetWaitingListBySpeciality($eventId, $specialityId)
    {
        $event = Event::findOrFail($eventId);
        $specialty = Specialty::findOrFail($specialityId);
        $eventSpecialty = EventSpecialty::where('event_id', $eventId)->where('specialty_id', $specialityId)->first();

        $appointments = collect();
        $summary = VisitStatistics::summarize(collect());
        if ($eventSpecialty) {
            // Today's counts for this specialty (same rules as every statistics screen)
            $summary = VisitStatistics::summarize(
                QueueService::scopeToday(Appointment::query())
                    ->where('event_specialty_id', $eventSpecialty->id)
                    ->get(['status', 'patient_id'])
            );

            $appointments = QueueService::scopeToday(Appointment::with(['day', 'hour', 'eventSpecialty.specialty']))
                ->where('event_specialty_id', $eventSpecialty->id)
                ->whereIn('status', QueueService::ACTIVE_STATUSES)
                ->orderByRaw("status IN ('DilatationSent', 'Dilatation')")
                ->orderBy('orderList')
                ->orderBy('id')
                ->get();
        }

        return response()->json([
            'appointments' => $appointments,
            'speciality' => $specialty->name,
            'event_specialty_id' => $eventSpecialty?->id,
            'is_open' => (bool) $eventSpecialty?->is_active,
            'has_dilatation' => (bool) $eventSpecialty?->has_dilatation,
            'summary' => $summary,
            'today' => QueueService::today(),
            'event' => ['id' => $event->id, 'name' => $event->name],
        ]);
    }

    /**
     * Public waiting-room screen: first 10 per specialty, today only.
     */
    public function getPublicWaitingList(Request $request)
    {
        $eventId = $request->query('event_id');
        // Public screen: never expose archived events
        $event = $eventId ? Event::where('is_archived', false)->find($eventId) : null;

        if (!$event) {
            return response()->json(['message' => 'Event not found', 'data' => [], 'event' => null], 404);
        }

        $rows = DB::table('appointments')
            ->join('event_specialties', 'appointments.event_specialty_id', '=', 'event_specialties.id')
            ->join('specialties', 'event_specialties.specialty_id', '=', 'specialties.id')
            ->join('days', 'appointments.day_id', '=', 'days.id')
            ->where('appointments.event_id', $event->id)
            ->whereDate('days.day_date', QueueService::today())
            ->whereIn('appointments.status', QueueService::QUEUE_STATUSES)
            ->orderBy('specialties.id')
            ->orderBy('appointments.orderList')
            ->orderBy('appointments.id')
            ->select('appointments.patient_id', 'appointments.full_name', 'appointments.sex', 'appointments.status',
                'appointments.is_priority', 'specialties.id as specialty_id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            if (count($grouped[$row->specialty_id] ?? []) >= 10) {
                continue;
            }
            $grouped[$row->specialty_id][] = [
                'patient_id' => $row->patient_id,
                'full_name' => $row->full_name,
                'sex' => $row->sex,
                'position' => count($grouped[$row->specialty_id] ?? []) + 1,
                'is_priority' => (bool) $row->is_priority,
                'status' => $row->status,
                'is_coming_soon' => $row->status === 'Commingsoon',
            ];
        }

        return response()->json([
            'data' => $grouped,
            'event' => ['id' => $event->id, 'name' => $event->name],
            'timestamp' => now()->toIso8601String(),
            'total_patients' => $rows->count(),
            'specialties_count' => count($grouped),
        ]);
    }

    private function pushDownAppointments($appointments): void
    {
        foreach ($appointments as $appointment) {
            if (!in_array($appointment->status, QueueService::QUEUE_STATUSES, true)) {
                abort(response()->json(['message' => 'أحد المرضى المختارين ليس في قائمة الانتظار'], 422));
            }
        }

        $this->queues->pushDown($appointments);
        foreach ($appointments as $appointment) {
            $this->audit('pushed_down', $appointment);
        }
    }

    private function audit(string $action, Appointment $appointment, array $extra = []): void
    {
        Log::info("Queue action: {$action}", [
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'by' => Auth::id(),
        ] + $extra);
    }
}
