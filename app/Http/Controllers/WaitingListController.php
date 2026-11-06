<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\Doctor;
use App\Models\DoctorAttendance;
use App\Models\Event;
use App\Models\EventSpecialty;
use App\Models\Specialty;
use App\Models\User;
use App\Services\DoctorVisitStats;
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
    private const RECENTLY_COMPLETED_ROWS = 10;


    private const PUBLIC_LIST_DEFAULT = 10;
    private const PUBLIC_LIST_MAX = 50;

    public function __construct(private QueueService $queues, private DoctorVisitStats $visitStats)
    {
    }

    /**
     * "Entered" (دخل): one patient, or a family together, goes in to the
     * chosen doctor. The doctor must be free.
     */
    public function enter(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:10',
            'ids.*' => 'integer|distinct',
            'doctor_id' => 'required|integer|exists:doctors,id',
            // A late relative joins the visit in progress with this doctor
            'join' => 'sometimes|boolean',
        ]);

        $appointments = Appointment::whereIn('id', $data['ids'])->get();
        $user = $request->user();
        if ($appointments->count() !== count($data['ids'])
            || $appointments->contains(fn ($a) => !$user->canAccessEvent($a->event_id))) {
            return response()->json(['message' => 'You are not authorized to access this event.'], 403);
        }

        $doctor = Doctor::findOrFail($data['doctor_id']);
        $join = (bool) ($data['join'] ?? false);
        $joined = $join && $this->queues->doctorBusy($doctor->id);
        $group = $this->queues->enter($appointments, $doctor, $join);
        foreach ($appointments as $appointment) {
            $this->audit($joined ? 'joined_visit' : 'entered', $appointment, ['doctor_id' => $doctor->id, 'visit_group' => $group]);
        }

        $who = $appointments->count() > 1 ? $appointments->count() . ' زوار' : 'الزائر';

        return response()->json([
            'message' => ($joined ? "أُضيف {$who} إلى الزيارة الجارية" : ($appointments->count() > 1 ? "دخل {$who}" : 'دخل الزائر'))
                . ' عند الطبيب ' . $doctor->full_name,
            'visit_group' => $group,
        ]);
    }

    /** Undo "Entered" (wrong click): the patient goes back to the head of the queue. */
    public function cancelEnter($id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->queues->cancelEnter($appointment);
        $this->audit('enter_cancelled', $appointment);

        return response()->json(['message' => 'أُلغي الدخول، عاد الزائر إلى أول الطابور']);
    }

    /** Family members (same phone) of the patient, for entering together. */
    public function relatives($id)
    {
        return response()->json(['relatives' => $this->queues->relatives(Appointment::findOrFail($id))]);
    }

    /**
     * "Passed" (إجتاز): examination done, the patient (with the family that
     * entered with them) moves to their next specialty.
     */
    public function Completed($id)
    {
        $appointment = Appointment::with('eventSpecialty.specialty')->findOrFail($id);
        $next = $this->queues->complete($appointment);

        $this->audit('completed', $appointment);

        if ($next->count() === 1) {
            $first = $next->first()->load('eventSpecialty.specialty');
            return response()->json([
                'message' => 'تم الفحص، انتقل الزائر إلى التخصص التالي',
                'next_specialty' => $first->eventSpecialty->specialty->name ?? '',
                'next_appointment' => $first,
            ]);
        }
        if ($next->count() > 1) {
            return response()->json(['message' => 'تم الفحص، انتقل ' . $next->count() . ' زوار إلى تخصصاتهم التالية']);
        }

        return response()->json(['message' => 'تم الفحص']);
    }

    /** Undo "Passed" (wrong click): back with the doctor, or at the head of the queue. */
    public function undoCompleted($id)
    {
        $appointment = Appointment::findOrFail($id);
        $withDoctor = $this->queues->undoComplete($appointment);
        $this->audit('completed_undone', $appointment);

        return response()->json([
            'message' => $withDoctor ? 'أُلغي «إجتاز»، عاد الزائر إلى الطبيب' : 'أُلغي «إجتاز»، الطبيب مشغول فعاد الزائر إلى أول الطابور',
            'with_doctor' => $withDoctor,
        ]);
    }

    /**
     * Doctors scheduled today in one specialty with their live numbers. Officers
     * see the doctors who arrived today: a doctor who left still finishes the
     * patients already in the queue.
     */
    public function doctorsPresent(Request $request, $eventSpecialtyId)
    {
        $eventSpecialty = EventSpecialty::findOrFail($eventSpecialtyId);
        $doctors = $this->visitStats->forEvent($eventSpecialty->event_id, [$eventSpecialty->id])
            ->get($eventSpecialty->id, collect());

        $seesAll = in_array($request->user()->role_id, [User::ROLE_SUPER_ADMIN, User::ROLE_ORGANIZER], true);

        return response()->json([
            'doctors' => $seesAll ? $doctors : $doctors->filter(fn ($d) => $d['arrived_at'] || $d['busy'])->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** "During the event" dashboard: every doctor today with visits, durations and alarms. */
    public function doctorVisits($eventId)
    {
        $event = Event::findOrFail($eventId);
        $bySpecialty = $this->visitStats->forEvent($event->id);
        $doctors = $bySpecialty->flatten(1)->sortBy([['specialty', 'asc'], ['full_name', 'asc']])->values();

        return response()->json([
            'doctors' => $doctors,
            'timing' => $this->visitStats->timing($event->id, $bySpecialty),
            'alarm_factor' => DoctorVisitStats::ALARM_FACTOR,
            'idle_minutes' => DoctorVisitStats::IDLE_MINUTES,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Special case (priority for the whole day). */
    public function Special($id)
    {
        $appointment = $this->queues->markSpecial(Appointment::findOrFail($id));
        $this->audit('special', $appointment);

        return response()->json([
            'success' => true,
            'message' => $appointment->status === 'Present'
                ? 'تم تحديد الزائر كحالة خاصة (أولوية)'
                : 'تم تحديد الزائر كحالة خاصة، سيُوجَّه بأولوية عند فتح تخصصه',
            'appointment' => $appointment,
        ]);
    }

    /**
     * Super admin / special admin: place 3 of the queue, with nothing on
     * screens to tell (unlike a special case). Logged with who and when.
     */
    public function moveToFront($id)
    {
        $appointment = $this->queues->moveToFront(Appointment::findOrFail($id));
        $this->audit('moved_to_front', $appointment);
        AppointmentLog::record('moved_to_front', $appointment->load('eventSpecialty.specialty'), [
            'specialty' => $appointment->eventSpecialty?->specialty?->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'أصبح الزائر في المركز ' . (\App\Services\QueueService::MOVE_UP_POSITION) . ' في طابور ' . ($appointment->eventSpecialty?->specialty?->name ?? 'التخصص'),
            'appointment' => $appointment,
        ]);
    }

    /** Specialty officer (تصحيح النظر): send the patient to the dilatation room. */
    public function Dilatation($id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->queues->sendToDilatation($appointment);
        $this->audit('dilatation_sent', $appointment);

        return response()->json(['message' => 'أُرسل الزائر إلى قاعة التمدد']);
    }

    /** Dilatation officer: the patient arrived in the dilatation room. */
    public function DilatationArrived($id)
    {
        $appointment = Appointment::findOrFail($id);
        $this->queues->receiveInDilatation($appointment);
        $this->audit('dilatation_received', $appointment);

        return response()->json(['message' => 'تم تسجيل وصول الزائر إلى التمدد']);
    }

    /** Dilatation officer: done, back to the specialty with priority. */
    public function FinishedDilatation($id)
    {
        $appointment = Appointment::with('eventSpecialty.specialty')->findOrFail($id);
        $this->queues->finishDilatation($appointment);
        $this->audit('dilatation_finished', $appointment);

        return response()->json([
            'message' => 'عاد الزائر إلى ' . ($appointment->eventSpecialty->specialty->name ?? 'التخصص') . ' (أولوية)',
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
    public function Commingsoon(Request $request, $id)
    {
        $places = $this->pushDownPlaces($request);
        $appointment = Appointment::findOrFail($id);
        $this->pushDownAppointments(collect([$appointment]), $places);

        return response()->json(['message' => 'تم إنزال الزائر ' . $places . ' مراتب']);
    }

    /** Push down several patients of the same event at once. */
    public function pushDownMany(Request $request)
    {
        $data = $request->validate([
            'appointment_ids' => 'required|array|min:1|max:100',
            'appointment_ids.*' => 'integer|distinct',
        ]);
        $places = $this->pushDownPlaces($request);

        $appointments = Appointment::whereIn('id', $data['appointment_ids'])->get();
        $user = $request->user();

        if ($appointments->count() !== count($data['appointment_ids'])
            || $appointments->contains(fn ($a) => !$user->canAccessEvent($a->event_id))) {
            return response()->json(['message' => 'You are not authorized to access this event.'], 403);
        }

        $this->pushDownAppointments($appointments, $places);

        return response()->json([
            'message' => 'تم إنزال ' . $appointments->count() . ' زائر ' . $places . ' مراتب',
        ]);
    }

    /** Open specialties today that the patient has not booked, for "send to specialty". */
    public function referralOptions($id)
    {
        $appointment = Appointment::findOrFail($id);
        // Booked by this patient number, or by the same person under another number
        $booked = Appointment::where('event_id', $appointment->event_id)
            ->where(fn ($q) => $q->where('patient_id', $appointment->patient_id)
                ->orWhere('identity_key', Appointment::identityKey($appointment->full_name, $appointment->birthday)))
            ->pluck('event_specialty_id');

        $options = EventSpecialty::with('specialty')
            ->where('event_id', $appointment->event_id)
            ->whereDate('opened_on', QueueService::today())
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
            'message' => 'تم إرسال الزائر إلى ' . ($target->specialty->name ?? 'التخصص الجديد'),
            'appointment' => $new,
            'position' => $this->queues->positionOf($new),
        ]);
    }

    /** The patient's other booked specialties, for «تبديل التخصص»; those they can't move to say why. */
    public function switchOptions($id)
    {
        $appointment = Appointment::findOrFail($id);

        $options = Appointment::with(['day:id,day_date', 'eventSpecialty:id,specialty_id', 'eventSpecialty.specialty:id,name'])
            ->where('event_id', $appointment->event_id)
            ->where('patient_id', $appointment->patient_id)
            ->whereKeyNot($appointment->id)
            ->get()
            ->map(function (Appointment $other) {
                $blocker = $this->queues->switchBlocker($other);

                return [
                    'appointment_id' => $other->id,
                    'name' => $other->eventSpecialty?->specialty?->name ?? '',
                    'status' => $other->status,
                    'can_switch' => $blocker === null,
                    'reason' => $blocker,
                    'queue_length' => $this->queues->queue($other->event_specialty_id)->count(),
                ];
            })
            ->sortByDesc('can_switch')
            ->values();

        return response()->json(['specialties' => $options]);
    }

    /** «تبديل التخصص»: the patient moves to the end of the queue of another specialty they booked. */
    public function switchSpecialty(Request $request, $id)
    {
        $data = $request->validate(['appointment_id' => 'required|integer']);
        $current = Appointment::with('eventSpecialty.specialty')->findOrFail($id);

        $target = $this->queues->switchSpecialty($current, $data['appointment_id']);
        $target->load('eventSpecialty.specialty');
        $this->audit('switched_specialty', $current, ['to_appointment' => $target->id]);
        AppointmentLog::record('specialty_switched', $target, [
            'from' => $current->eventSpecialty?->specialty?->name,
            'to' => $target->eventSpecialty?->specialty?->name,
        ]);

        return response()->json([
            'message' => 'انتقل الزائر إلى ' . ($target->eventSpecialty?->specialty?->name ?? 'التخصص المختار'),
            'appointment' => $target,
            'position' => $this->queues->positionOf($target),
        ]);
    }

    /**
     * Super admin, visit day: open a specialty's queue for today when its
     * doctor is there (waiting patients are sent to it), or close it (no new
     * patients; the current queue is still served).
     */
    public function toggleOpen($eventSpecialtyId)
    {
        $eventSpecialty = EventSpecialty::findOrFail($eventSpecialtyId);

        if ($eventSpecialty->isOpenToday()) {
            $this->queues->closeSpecialty($eventSpecialty);
            Log::info('Specialty closed', ['event_specialty_id' => $eventSpecialty->id, 'by' => Auth::id()]);

            return response()->json([
                'message' => 'أُغلق التخصص، لن يُرسل إليه زوار جدد',
                'is_open' => false,
            ]);
        }

        $doctorPresent = DoctorAttendance::whereNotNull('arrived_at')->whereNull('left_at')->whereNull('on_break_since')
            ->whereHas('day', fn ($q) => $q->where('event_specialty_id', $eventSpecialty->id)->whereDate('day_date', QueueService::today()))
            ->exists();
        if (!$doctorPresent) {
            return response()->json(['message' => 'لا يُفتح الطابور دون طبيب حاضر، سجّل وصول طبيب أولاً'], 422);
        }

        $result = $this->queues->openSpecialty($eventSpecialty);
        Log::info('Specialty opened', ['event_specialty_id' => $eventSpecialty->id, 'by' => Auth::id()] + $result);

        $message = 'فُتح التخصص';
        if ($result['sent'] + $result['moved'] > 0) {
            $message .= '، أُرسل إليه ' . ($result['sent'] + $result['moved']) . ' زائر';
            if ($result['moved'] > 0) {
                $message .= ' (' . $result['moved'] . ' من طوابير أخرى)';
            }
        }

        return response()->json(['message' => $message, 'is_open' => true] + $result);
    }

    /**
     * Officer's home: every specialty of the event with today's live counts,
     * plus the dilatation room of specialties that have one.
     */
    public function stations($eventId)
    {
        return response()->json(Cache::remember("stations:{$eventId}", (int) config('app.live_cache_seconds'), fn () => $this->stationsPayload($eventId)));
    }

    private function stationsPayload($eventId): array
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
                    'is_open' => $es->isOpenToday(),
                    'is_full' => $es->isOpenToday() && $this->queues->capacity($es->id)['full'],
                    'has_dilatation' => (bool) $es->has_dilatation,
                    'in_queue' => $sum(QueueService::QUEUE_STATUSES),
                    'waiting' => $sum(['Waiting']),
                    'completed' => $sum(['Completed']),
                    'in_visit' => $sum(['Entered']),
                    'dilatation_coming' => $sum(['DilatationSent']),
                    'dilatation_in_room' => $sum(['Dilatation']),
                ];
            })
            ->sortBy('name')
            ->values();

        return [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'today' => QueueService::today(),
            'stations' => $stations->all(),
        ];
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
        $recentlyCompleted = collect();
        $summary = VisitStatistics::summarize(collect());
        if ($eventSpecialty) {
            // Last ones who passed, so a wrong «إجتاز» can be undone
            $recentlyCompleted = QueueService::scopeToday(Appointment::with('doctor'))
                ->where('event_specialty_id', $eventSpecialty->id)
                ->where('status', 'Completed')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->limit(self::RECENTLY_COMPLETED_ROWS)
                ->get(['id', 'patient_id', 'full_name', 'doctor_id', 'visit_group', 'completed_at'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'patient_id' => $a->patient_id,
                    'full_name' => $a->full_name,
                    'doctor_name' => $a->doctor?->full_name,
                    'visit_group' => $a->visit_group,
                    'completed_at' => $a->completed_at?->toIso8601String(),
                ]);

            // Today's counts for this specialty (same rules as every statistics screen)
            $summary = VisitStatistics::summarize(
                QueueService::scopeToday(Appointment::query())
                    ->where('event_specialty_id', $eventSpecialty->id)
                    ->get(['status', 'patient_id'])
            );

            // Polled by every officer: only the nested columns the screen reads
            $appointments = QueueService::scopeToday(Appointment::with([
                'day:id,day_date', 'hour:id,time', 'eventSpecialty:id,specialty_id', 'eventSpecialty.specialty:id,name',
            ]))
                ->where('event_specialty_id', $eventSpecialty->id)
                ->whereIn('status', QueueService::ACTIVE_STATUSES)
                ->orderByRaw("status IN ('DilatationSent', 'Dilatation')")
                ->orderBy('orderList')
                ->orderBy('id')
                ->get();
        }

        return response()->json([
            'appointments' => $appointments,
            'recently_completed' => $recentlyCompleted,
            'speciality' => $specialty->name,
            'event_specialty_id' => $eventSpecialty?->id,
            'is_open' => (bool) $eventSpecialty?->isOpenToday(),
            // Full: a doctor left and the others' planned visits are reached; only those in it are served
            'capacity' => $eventSpecialty ? $this->queues->capacity($eventSpecialty->id) : null,
            'has_dilatation' => (bool) $eventSpecialty?->has_dilatation,
            'summary' => $summary,
            'today' => QueueService::today(),
            'event' => ['id' => $event->id, 'name' => $event->name],
        ]);
    }

    /**
     * Public waiting-room screen: first N per specialty (?limit=, default 10), today only.
     */
    public function getPublicWaitingList(Request $request)
    {
        $eventId = $request->query('event_id');
        // Rows per specialty chosen on the hall screen's settings (1-50)
        $limit = min(self::PUBLIC_LIST_MAX, max(1, (int) $request->query('limit', self::PUBLIC_LIST_DEFAULT)));
        // Public screen: never expose archived events
        $event = $eventId ? Event::where('is_archived', false)->find($eventId) : null;

        if (!$event) {
            return response()->json(['message' => 'Event not found', 'data' => [], 'event' => null], 404);
        }

        // Every hall screen and phone shows the same answer: compute it once for all of them
        return response()->json(Cache::remember(
            "public-waitinglist:{$event->id}:{$limit}",
            (int) config('app.live_cache_seconds'),
            fn () => $this->publicWaitingListPayload($event, $limit),
        ));
    }

    private function publicWaitingListPayload(Event $event, int $limit): array
    {
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

        // Whole queue length per specialty (the screen lists only the first N)
        $counts = $rows->countBy('specialty_id');

        // With the doctor now (دخل): «عند الطبيب» on top of their specialty until «إجتاز»
        $inside = DB::table('appointments')
            ->join('event_specialties', 'appointments.event_specialty_id', '=', 'event_specialties.id')
            ->join('days', 'appointments.day_id', '=', 'days.id')
            ->where('appointments.event_id', $event->id)
            ->whereDate('days.day_date', QueueService::today())
            ->where('appointments.status', 'Entered')
            ->orderBy('appointments.entered_at')
            ->orderBy('appointments.id')
            ->select('appointments.patient_id', 'appointments.full_name', 'appointments.sex',
                'appointments.doctor_id', 'event_specialties.specialty_id')
            ->get();

        // Free doctors per specialty: arrived today, not left or on a break, nobody with them.
        // As many visitors as free doctors are invited in («تفضل بالدخول»).
        $busyDoctors = $inside->pluck('doctor_id')->filter()->unique();
        $freeDoctors = DB::table('doctor_attendances')
            ->join('days', 'doctor_attendances.day_id', '=', 'days.id')
            ->join('event_specialties', 'days.event_specialty_id', '=', 'event_specialties.id')
            ->where('event_specialties.event_id', $event->id)
            ->whereDate('days.day_date', QueueService::today())
            ->whereNotNull('doctor_attendances.arrived_at')
            ->whereNull('doctor_attendances.left_at')
            ->whereNull('doctor_attendances.on_break_since')
            ->whereNotIn('doctor_attendances.doctor_id', $busyDoctors)
            ->select('event_specialties.specialty_id', 'doctor_attendances.doctor_id')
            ->distinct()
            ->get()
            ->countBy('specialty_id');

        $grouped = [];
        $invitedCount = [];
        foreach ($rows as $row) {
            if (count($grouped[$row->specialty_id] ?? []) >= $limit) {
                continue;
            }
            // A visitor pushed down (absent when called) is not invited again yet
            $invited = $row->status !== 'Commingsoon'
                && ($invitedCount[$row->specialty_id] ?? 0) < ($freeDoctors[$row->specialty_id] ?? 0);
            if ($invited) {
                $invitedCount[$row->specialty_id] = ($invitedCount[$row->specialty_id] ?? 0) + 1;
            }
            $grouped[$row->specialty_id][] = [
                'patient_id' => $row->patient_id,
                'full_name' => $row->full_name,
                'sex' => $row->sex,
                'position' => count($grouped[$row->specialty_id] ?? []) + 1,
                'is_priority' => (bool) $row->is_priority,
                'status' => $row->status,
                'is_coming_soon' => $row->status === 'Commingsoon',
                'invited' => $invited,
            ];
        }

        $calling = $inside->groupBy('specialty_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'patient_id' => $r->patient_id,
                'full_name' => $r->full_name,
                'sex' => $r->sex,
            ])->values());

        // Specialties that worked today and take no new patients now (closed
        // after their doctors left, or full after one left); not those whose doctor hasn't come yet
        $closed = EventSpecialty::where('event_id', $event->id)
            ->whereHas('days', fn ($q) => $q->whereDate('day_date', QueueService::today())
                ->whereHas('attendances', fn ($a) => $a->whereNotNull('arrived_at')))
            ->get(['id', 'specialty_id', 'opened_on'])
            ->filter(fn ($es) => !$es->isOpenToday() || $this->queues->capacity($es->id)['full'])
            ->pluck('specialty_id')
            ->values();

        return [
            'data' => $grouped,
            // With the doctor (Entered), shown until they pass
            'calling' => $calling,
            'closed_specialties' => $closed,
            'counts' => $counts,
            'event' => ['id' => $event->id, 'name' => $event->name],
            'timestamp' => now()->toIso8601String(),
            'total_patients' => $rows->count(),
            'specialties_count' => count($grouped),
        ];
    }

    /** Optional "places" (1-50) chosen with the slider; defaults to PUSH_DOWN_PLACES. */
    private function pushDownPlaces(Request $request): int
    {
        $data = $request->validate(['places' => 'sometimes|integer|min:1|max:50']);

        return (int) ($data['places'] ?? QueueService::PUSH_DOWN_PLACES);
    }

    private function pushDownAppointments($appointments, int $places = QueueService::PUSH_DOWN_PLACES): void
    {
        foreach ($appointments as $appointment) {
            if (!in_array($appointment->status, QueueService::QUEUE_STATUSES, true)) {
                abort(response()->json(['message' => 'أحد الزوار المختارين ليس في قائمة الانتظار'], 422));
            }
        }

        $this->queues->pushDown($appointments, $places);
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
