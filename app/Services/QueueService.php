<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Day;
use App\Models\Doctor;
use App\Models\DoctorAttendance;
use App\Models\DoctorInterval;
use App\Models\Event;
use App\Models\EventSpecialty;
use App\Models\Hour;
use App\Exceptions\RuleException;
use App\Http\Controllers\PublicTurnController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * All visit-day queue rules live here.
 *
 * - A patient only takes part in the appointments booked for TODAY.
 * - A patient is active in one specialty at a time (Present, Pushed down,
 *   Dilatation or Entered = with the doctor); their other appointments today
 *   wait as "Waiting".
 * - A specialty queue = today's Present + Commingsoon appointments, ordered by
 *   orderList (1, 2, 3 ...). Priority patients (special case, back from
 *   dilatation) form a block at the head, in the order they arrived.
 * - A specialty only receives patients once the super admin opened it today
 *   (its doctor is there). A patient goes to the least busy open specialty
 *   they booked; with none open they wait until one opens.
 * - Once one of its doctors left, a specialty only receives patients while
 *   its load stays under the visits planned for the doctors still present
 *   (capacity()); full, it serves the patients already in it.
 */
class QueueService
{
    public const TIMEZONE = 'Africa/Algiers';
    public const QUEUE_STATUSES = ['Present', 'Commingsoon'];
    // Dilatation is a hidden station of a specialty (تصحيح النظر): the patient is
    // sent there (DilatationSent), received by the dilatation officer (Dilatation),
    // then returns to the head of the specialty's queue.
    public const DILATATION_STATUSES = ['DilatationSent', 'Dilatation'];
    // Entered (دخل): with a doctor; out of the queue but still this specialty's patient
    public const ACTIVE_STATUSES = ['Present', 'Commingsoon', 'DilatationSent', 'Dilatation', 'Entered'];
    // What can count against a specialty's capacity
    public const LOAD_STATUSES = [...self::ACTIVE_STATUSES, 'Completed'];
    // Attempts of a transaction that lost a deadlock
    private const RETRIES = 3;
    public const PUSH_DOWN_PLACES = 5;
    public const TRANSFER_POSITION = 3;
    // «قدّمه» by the super/special admin: this place in the queue, ahead of everyone after it
    public const MOVE_UP_POSITION = 3;
    // When a specialty opens, the first places of other queues are never moved to it
    public const PROTECTED_PLACES = 5;

    public static function today(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    /** Restrict an appointment query to appointments booked for today. */
    public static function scopeToday($query)
    {
        return $query->whereHas('day', fn ($q) => $q->whereDate('day_date', self::today()));
    }

    public function isToday(Appointment $appointment): bool
    {
        $date = $appointment->day?->day_date ?? Day::whereKey($appointment->day_id)->value('day_date');

        return $date && Carbon::parse($date)->toDateString() === self::today();
    }

    /** The patient's appointments for today in one event, primary specialty first. */
    public function todaysAppointments(int $eventId, int $patientId): Collection
    {
        return self::scopeToday(Appointment::query())
            ->where('event_id', $eventId)
            ->where('patient_id', $patientId)
            ->orderByDesc('specialty_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Ordered queue of one event specialty for today. */
    public function queue(int $eventSpecialtyId): Collection
    {
        return self::scopeToday(Appointment::query())
            ->where('event_specialty_id', $eventSpecialtyId)
            ->whereIn('status', self::QUEUE_STATUSES)
            ->orderBy('orderList')
            ->orderBy('id')
            ->get();
    }

    /** 1-based place in its queue, or null when not queued. */
    public function positionOf(Appointment $appointment): ?int
    {
        $index = $this->queue($appointment->event_specialty_id)->search(fn ($a) => $a->id === $appointment->id);

        return $index === false ? null : $index + 1;
    }

    // ------------------------------------------------------------- check-in

    /**
     * Reception check-in (QR scan or typed number). The patient joins the
     * least busy open specialty they booked today, the others wait. With no
     * open specialty, all wait and null is returned.
     * $firstAppointmentId (special case) is used when its specialty is open.
     *
     * @throws RuleException
     */
    public function checkIn(int $eventId, int $patientId, ?int $firstAppointmentId = null, bool $priority = false): ?Appointment
    {
        return DB::transaction(function () use ($eventId, $patientId, $firstAppointmentId, $priority) {
            $todays = $this->todaysAppointments($eventId, $patientId);

            if ($todays->isEmpty()) {
                $this->failNoAppointmentToday($eventId, $patientId);
            }

            if (!$this->awaitsReception($todays)) {
                throw new RuleException('الزائر مسجل حضوره اليوم مسبقاً', 409);
            }
            // Back after «غائب»: admitted once today, a referral may have put them past the limit
            if ($todays->every(fn ($a) => $a->checked_in_at === null)) {
                $this->assertWithinLimit($eventId, $patientId);
            }

            // Exams finished before «غائب» stay finished
            $arriving = $todays->where('status', 'Pending');
            Appointment::whereIn('id', $arriving->pluck('id'))->update(['checked_in_at' => now()]);

            $special = $priority || $todays->contains('is_special', true);
            $open = $this->openSpecialtyIds($eventId);
            $preferred = $firstAppointmentId ? $arriving->firstWhere('id', $firstAppointmentId) : null;
            $first = $preferred && in_array($preferred->event_specialty_id, $open)
                ? $preferred
                : $this->pickLeastBusy($arriving, $open);

            foreach ($arriving as $other) {
                if ($other->id !== $first?->id) {
                    $this->setWaiting($other);
                }
            }

            if (!$first) {
                $this->promoteAfterCommit($eventId, $patientId);

                return null;
            }

            $first->status = 'Present';
            $this->placeInQueue($first, null, $special);

            return $first->fresh();
        });
    }

    /**
     * «لم يأت بعد»: reception checked this patient in by mistake. All of today's
     * appointments go back to booked and the patient leaves the queue; when they
     * really arrive, a normal check-in puts them last. The special-case mark stays.
     * Refused once the visit started (doctor, dilatation, a specialty done, sent on
     * by an officer): the patient was there, so it was no mistake.
     *
     * @throws RuleException
     */
    public function undoCheckIn(int $eventId, int $patientId): void
    {
        DB::transaction(function () use ($eventId, $patientId) {
            // Queue before the patient's rows, as enter() does: «دخل» at the same moment must not deadlock
            $queuedIn = self::scopeToday(Appointment::query())
                ->where('event_id', $eventId)
                ->where('patient_id', $patientId)
                ->whereIn('status', self::QUEUE_STATUSES)
                ->value('event_specialty_id');
            if ($queuedIn) {
                $this->lockQueue($queuedIn);
            }

            $todays = $this->todaysAppointments($eventId, $patientId);

            if ($todays->isEmpty()) {
                $this->failNoAppointmentToday($eventId, $patientId);
            }
            if ($todays->every(fn ($a) => $a->status === 'Pending')) {
                throw new RuleException('الزائر لم يُسجَّل حضوره اليوم', 409);
            }
            if ($todays->contains(fn ($a) => $a->status === 'Entered')) {
                throw new RuleException('الزائر عند الطبيب حالياً، لا يمكن إلغاء حضوره', 422);
            }
            if ($todays->contains(fn ($a) => $a->status === 'Completed')) {
                throw new RuleException('أنهى الزائر فحصاً اليوم، لا يمكن إلغاء حضوره', 422);
            }
            if ($todays->contains(fn ($a) => $a->dilatation_sent_at !== null)) {
                throw new RuleException('مرّ الزائر بقاعة التمدد، لا يمكن إلغاء حضوره', 422);
            }
            // refer() is the only way to be active without an arrival time
            if ($todays->contains(fn ($a) => $a->status !== 'Pending' && $a->checked_in_at === null)) {
                throw new RuleException('وجّهه مسؤول التخصص إلى تخصص آخر، لا يمكن إلغاء حضوره', 422);
            }

            foreach ($todays as $appointment) {
                $appointment->checked_in_at = null;
                $this->leaveQueue($appointment, 'Pending');
            }
        });
    }

    /**
     * «غائب»: the officer called the patient and they are not there. They leave
     * the queue and everything not finished today goes back to Pending, so they
     * must go through reception again and join the end of a queue. Unlike
     * «لم يأت بعد» the patient did come: finished exams stay, and checked_in_at
     * stays too, which is how a check-in recognises the return.
     *
     * @throws RuleException
     */
    public function markAbsent(Appointment $appointment): void
    {
        $this->assertStatus($appointment, self::QUEUE_STATUSES, 'الزائر ليس في الطابور');
        if (!$this->isToday($appointment)) {
            throw new RuleException('هذا الموعد ليس اليوم', 422);
        }

        DB::transaction(function () use ($appointment) {
            // Queue before the patient's rows, as enter() does: «دخل» at the same moment must not deadlock
            $this->lockQueue($appointment->event_specialty_id);
            $todays = $this->todaysAppointments($appointment->event_id, $appointment->patient_id);

            if (!in_array($todays->firstWhere('id', $appointment->id)?->status, self::QUEUE_STATUSES, true)) {
                throw new RuleException('الزائر ليس في الطابور', 409);
            }

            foreach ($todays->where('status', '!=', 'Completed') as $row) {
                $this->leaveQueue($row, 'Pending');
            }
        });
    }

    /**
     * «لم يأت بعد» on one finished specialty: the exam never happened, the
     * patient's other exams stay. With other appointments today it goes back on
     * hold (and to its queue if they are nowhere else); alone, the patient goes
     * back to not arrived. Reception then may delete it.
     *
     * @throws RuleException
     */
    public function undoVisit(Appointment $appointment): void
    {
        $this->assertStatus($appointment, ['Completed'], 'هذا الموعد ليس منتهياً');
        if (!$this->isToday($appointment)) {
            throw new RuleException('يمكن إلغاء فحص اليوم فقط', 422);
        }

        DB::transaction(function () use ($appointment) {
            $this->lockQueue($appointment->event_specialty_id);
            $todays = $this->todaysAppointments($appointment->event_id, $appointment->patient_id);
            $row = $todays->firstWhere('id', $appointment->id);
            if (!$row || $row->status !== 'Completed') {
                throw new RuleException('هذا الموعد ليس منتهياً', 409);
            }

            $this->clearVisit($row);
            $others = $todays->reject(fn ($a) => $a->id === $row->id);
            if ($others->isEmpty() || $this->awaitsReception($others)) {
                $row->checked_in_at = null;
                $this->leaveQueue($row, 'Pending');
                return;
            }
            $this->setWaiting($row);
            $this->promoteNext($row->event_id, $row->patient_id);
        });
    }

    // ------------------------------------------------- specialty officer

    /**
     * "Entered" (دخل): the patients (one, or a family together) go in to the
     * doctor. They leave the queue; a doctor sees one patient or family at a time.
     * $join: a relative who came late joins the doctor's visit in progress (same
     * visit group, they pass together); with no visit in progress it is a new one.
     *
     * @return string the visit group shared by these patients
     */
    public function enter(Collection $appointments, Doctor $doctor, bool $join = false): string
    {
        return DB::transaction(function () use ($appointments, $doctor, $join) {
            $eventSpecialtyIds = $appointments->pluck('event_specialty_id')->unique();
            if ($eventSpecialtyIds->count() !== 1) {
                throw new RuleException('الزوار المختارون ليسوا في نفس التخصص', 422);
            }
            $eventSpecialtyId = (int) $eventSpecialtyIds->first();
            $this->lockQueue($eventSpecialtyId);

            foreach ($appointments as $appointment) {
                $appointment->refresh();
                $this->assertStatus($appointment, self::QUEUE_STATUSES, "الزائر {$appointment->full_name} ليس في قائمة الانتظار");
                if (!$this->isToday($appointment)) {
                    throw new RuleException('هذا الموعد ليس اليوم', 422);
                }
            }

            $scheduled = Day::where('event_specialty_id', $eventSpecialtyId)
                ->whereDate('day_date', self::today())
                ->whereHas('intervals', fn ($q) => $q->where('doctor_id', $doctor->id))
                ->exists();
            if (!$scheduled) {
                throw new RuleException("الطبيب {$doctor->full_name} غير مبرمج في هذا التخصص اليوم", 422);
            }
            // Arrived today; a doctor who left still sees the patients already in the queue
            $attendance = DoctorAttendance::where('doctor_id', $doctor->id)->whereNotNull('arrived_at')
                ->whereHas('day', fn ($q) => $q->where('event_specialty_id', $eventSpecialtyId)->whereDate('day_date', self::today()))
                ->first();
            if (!$attendance) {
                throw new RuleException("الطبيب {$doctor->full_name} لم يُسجَّل وصوله اليوم", 422);
            }
            $current = self::scopeToday(Appointment::query())
                ->where('doctor_id', $doctor->id)->where('status', 'Entered')
                ->lockForUpdate()->first();
            if ($current && !$join) {
                throw new RuleException("الطبيب {$doctor->full_name} مشغول مع زائر آخر، اضغط إجتاز أولاً", 409);
            }
            // A relative may still join the visit the doctor finishes before the break
            if (!$current && $attendance->isOnBreak()) {
                throw new RuleException("الطبيب {$doctor->full_name} في استراحة، سجّل عودته أولاً", 422);
            }
            if ($current && (int) $current->event_specialty_id !== $eventSpecialtyId) {
                throw new RuleException("الطبيب {$doctor->full_name} مع زائر في تخصص آخر", 422);
            }

            $group = $current?->visit_group ?: (string) Str::uuid();
            if ($current && !$current->visit_group) {
                $current->visit_group = $group;
                $current->save();
            }
            foreach ($appointments as $appointment) {
                $appointment->doctor_id = $doctor->id;
                $appointment->entered_at = now();
                $appointment->completed_at = null;
                $appointment->visit_seconds = null;
                $appointment->visit_group = $group;
                $this->leaveQueue($appointment, 'Entered');
            }

            return $group;
        });
    }

    /** Undo "Entered" (wrong click): back to the head of the queue. */
    public function cancelEnter(Appointment $appointment): void
    {
        $this->assertStatus($appointment, ['Entered'], 'الزائر ليس عند الطبيب');
        DB::transaction(function () use ($appointment) {
            $this->clearVisit($appointment);
            $appointment->status = 'Present';
            $this->placeInQueue($appointment, 1, (bool) $appointment->is_special);
        });
    }

    /** Whether the doctor has a patient (or family) with them right now. */
    public function doctorBusy(int $doctorId): bool
    {
        return self::scopeToday(Appointment::query())
            ->where('doctor_id', $doctorId)
            ->where('status', 'Entered')
            ->exists();
    }

    /**
     * "Passed": examination done; the patient moves on to their next specialty.
     * A family that entered together passes together; with $alone only this
     * patient passes and the others stay with the doctor. The visit time not yet
     * given to those who already passed is split equally between those still in.
     *
     * @return Collection the next appointments the patients were sent to
     */
    public function complete(Appointment $appointment, bool $alone = false): Collection
    {
        $this->assertStatus($appointment, ['Entered'], in_array($appointment->status, self::QUEUE_STATUSES, true)
            ? 'اضغط «دخل» أولاً مع اختيار الطبيب'
            : 'الزائر ليس عند الطبيب');

        return DB::transaction(function () use ($appointment, $alone) {
            $visit = Appointment::whereIn('status', ['Entered', 'Completed'])
                ->where(fn ($q) => $appointment->visit_group
                    ? $q->where('visit_group', $appointment->visit_group)
                    : $q->whereKey($appointment->id))
                ->lockForUpdate()
                ->get();
            $inside = $visit->where('status', 'Entered');
            $group = $alone ? $inside->where('id', $appointment->id) : $inside;

            $now = now();
            $next = collect();
            // The visit lasts from the first one in (a relative may have joined late)
            $start = $visit->pluck('entered_at')->filter()->min();
            $share = $start
                ? intdiv(max(0, (int) $start->diffInSeconds($now, true) - (int) $visit->sum('visit_seconds')), max(1, $inside->count()))
                : null;
            foreach ($group as $a) {
                $a->completed_at = $now;
                $a->visit_seconds = $share;
                $this->leaveQueue($a, 'Completed');
                if ($promoted = $this->promoteNext($a->event_id, $a->patient_id)) {
                    $next->push($promoted);
                }
            }

            return $next;
        });
    }

    /**
     * Undo "Passed" (wrong click): the patient (with the family who passed in the
     * same click) goes back to the doctor and the visit goes on, or to the head of
     * the queue when the doctor is in another visit. The next specialty started
     * by the pass goes back on hold. Refused once they are with a doctor or in
     * dilatation elsewhere.
     *
     * @return bool true when back with the doctor, false when back in the queue
     * @throws RuleException
     */
    public function undoComplete(Appointment $appointment): bool
    {
        $this->assertStatus($appointment, ['Completed'], 'الزائر لم يجتز هذا التخصص');
        if (!$this->isToday($appointment)) {
            throw new RuleException('يمكن التراجع عن «إجتاز» في يوم الفحص فقط', 422);
        }

        return DB::transaction(function () use ($appointment) {
            // One click stamps the same completed_at: a relative who passed apart stays passed
            $passed = fn () => Appointment::where('status', 'Completed')
                ->where(fn ($q) => $appointment->visit_group
                    ? $q->where('visit_group', $appointment->visit_group)->where('completed_at', $appointment->completed_at)
                    : $q->whereKey($appointment->id));
            $patientIds = $passed()->pluck('patient_id');
            $movedOn = fn () => self::scopeToday(Appointment::query())
                ->where('event_id', $appointment->event_id)
                ->whereIn('patient_id', $patientIds)
                ->whereIn('status', self::ACTIVE_STATUSES);

            // Queues before the patients' rows, as enter() does: no deadlock with «دخل»
            $movedOn()->pluck('event_specialty_id')
                ->push($appointment->event_specialty_id)
                ->unique()->sort()
                ->each(fn ($id) => $this->lockQueue($id));

            $group = $passed()->lockForUpdate()->get();
            if ($group->isEmpty()) {
                throw new RuleException('الزائر لم يجتز هذا التخصص', 409);
            }
            $active = $movedOn()->lockForUpdate()->get();
            if ($active->contains(fn ($a) => $a->status === 'Entered')) {
                throw new RuleException('الزائر عند الطبيب في تخصص آخر، لا يمكن التراجع', 422);
            }
            if ($active->contains(fn ($a) => in_array($a->status, self::DILATATION_STATUSES, true))) {
                throw new RuleException('الزائر في التمدد، لا يمكن التراجع', 422);
            }

            foreach ($active as $next) {
                $this->setWaiting($next);
            }

            $doctorId = $group->first()->doctor_id;
            // Relatives of the same visit still inside don't make the doctor busy
            $otherVisit = $doctorId && self::scopeToday(Appointment::query())
                ->where('doctor_id', $doctorId)->where('status', 'Entered')
                ->get(['visit_group'])
                ->contains(fn ($a) => !$appointment->visit_group || $a->visit_group !== $appointment->visit_group);
            $withDoctor = $doctorId && !$otherVisit;

            // Reversed: each one takes the head of the queue, so the first stays first
            foreach ($group->reverse() as $a) {
                if ($withDoctor) {
                    $a->status = 'Entered';
                    $a->completed_at = null;
                    $a->visit_seconds = null;
                    $a->save();
                } else {
                    $this->clearVisit($a);
                    $a->status = 'Present';
                    $this->placeInQueue($a, 1, (bool) $a->is_special);
                }
            }

            return $withDoctor;
        });
    }

    /**
     * Other patients of the same family (same phone, last 9 digits) with an
     * appointment in this event today. Those queued in the same specialty can
     * enter together; the others are returned with the reason they can't.
     */
    public function relatives(Appointment $appointment): Collection
    {
        $phones = collect([$appointment->phone, $appointment->phone2])
            ->map(fn ($p) => PublicTurnController::phoneKey($p))
            ->filter(fn ($p) => strlen($p) === 9)
            ->unique()
            ->values();
        if ($phones->isEmpty()) {
            return collect();
        }

        $matches = self::scopeToday(Appointment::with('eventSpecialty.specialty'))
            ->where('event_id', $appointment->event_id)
            ->where('patient_id', '!=', $appointment->patient_id)
            ->where(function ($q) use ($phones) {
                foreach ($phones as $phone) {
                    $q->orWhereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ?", [$phone])
                        ->orWhereRaw("RIGHT(REGEXP_REPLACE(phone2, '[^0-9]', ''), 9) = ?", [$phone]);
                }
            })
            ->get();

        $queue = $this->queue($appointment->event_specialty_id)->pluck('id')->values();

        return $matches->groupBy('patient_id')->map(function (Collection $rows) use ($appointment, $queue) {
            $here = $rows->firstWhere('event_specialty_id', $appointment->event_specialty_id);
            $active = $rows->first(fn ($a) => in_array($a->status, self::ACTIVE_STATUSES, true));
            $place = $here ? $queue->search($here->id) : false;

            $reason = match (true) {
                $here && $place !== false => null,
                $here && $here->status === 'Completed' => 'أنهى هذا التخصص',
                $here && $here->status === 'Entered' => 'عند الطبيب الآن',
                $this->awaitsReception($rows) => 'لم يصل بعد',
                $active && $active->event_specialty_id !== $appointment->event_specialty_id
                    => 'في تخصص آخر: ' . ($active->eventSpecialty?->specialty?->name ?? ''),
                $here && in_array($here->status, self::DILATATION_STATUSES, true) => 'في التمدد',
                (bool) $here => 'ينتظر دوره في تخصص آخر',
                default => 'ليس له موعد في هذا التخصص',
            };
            $a = $here ?? $rows->first();

            return [
                'id' => $a->id,
                'patient_id' => $a->patient_id,
                'full_name' => $a->full_name,
                'sex' => $a->sex,
                'status' => $a->status,
                'place' => $place === false ? null : $place + 1,
                'can_enter' => $reason === null,
                'reason' => $reason,
            ];
        })->sortBy([['can_enter', 'desc'], ['place', 'asc']])->values();
    }

    /** Pushes the given appointments of ONE queue back $places places (default PUSH_DOWN_PLACES). */
    public function pushDown(Collection $appointments, int $places = self::PUSH_DOWN_PLACES): void
    {
        DB::transaction(function () use ($appointments, $places) {
            foreach ($appointments->groupBy('event_specialty_id') as $eventSpecialtyId => $group) {
                $this->lockQueue($eventSpecialtyId);
                $ids = $group->pluck('id')->all();
                $queue = $this->queue($eventSpecialtyId)->values();
                // A pushed patient loses priority, so never lands inside the priority block
                $lastPriority = -1;
                foreach ($queue as $i => $a) {
                    if ($a->is_priority && !in_array($a->id, $ids, true)) {
                        $lastPriority = $i;
                    }
                }

                // Moving each selected patient back N places keeps the order
                // of everyone else, also when several are pushed at once.
                $ordered = $queue
                    ->map(fn ($a, $i) => ['a' => $a, 'key' => in_array($a->id, $ids, true) ? max($i + $places, $lastPriority) + 0.5 : $i])
                    ->sortBy('key')
                    ->pluck('a')
                    ->values();

                foreach ($ordered as $i => $a) {
                    if (in_array($a->id, $ids, true)) {
                        $a->status = 'Commingsoon';
                        $a->is_priority = false;
                    }
                    $a->orderList = $i + 1;
                    $a->save();
                }
            }
        });
    }

    /** Specialty officer: the doctor ordered dilatation; the patient leaves the queue. */
    public function sendToDilatation(Appointment $appointment): void
    {
        // From the queue, or from the doctor's room (the doctor ordered it during the visit)
        $this->assertStatus($appointment, [...self::QUEUE_STATUSES, 'Entered'], 'الزائر ليس في قائمة الانتظار');
        if (!EventSpecialty::whereKey($appointment->event_specialty_id)->value('has_dilatation')) {
            throw new RuleException('هذا التخصص ليس له قاعة تمدد', 422);
        }

        DB::transaction(function () use ($appointment) {
            $this->clearVisit($appointment);
            $appointment->dilatation_sent_at = now();
            $appointment->dilatation_started_at = null;
            $this->leaveQueue($appointment, 'DilatationSent');
        });
    }

    /** Dilatation officer: the patient arrived in the dilatation room (timer starts). */
    public function receiveInDilatation(Appointment $appointment): void
    {
        $this->assertStatus($appointment, ['DilatationSent'], 'الزائر ليس في الطريق إلى التمدد');
        $appointment->status = 'Dilatation';
        $appointment->dilatation_started_at = now();
        $appointment->save();
    }

    /**
     * Dilatation officer: dilatation done, the patient goes back to the
     * specialty at the head of the queue, behind the priority patients already
     * waiting (special cases, earlier dilatation returns).
     */
    public function finishDilatation(Appointment $appointment): void
    {
        $this->assertStatus($appointment, self::DILATATION_STATUSES, 'الزائر ليس في التمدد');
        DB::transaction(function () use ($appointment) {
            $appointment->status = 'Present';
            $this->placeInQueue($appointment, null, true);
        });
    }

    /** Today's patients of a specialty's dilatation station, in the order they were sent. */
    public function dilatationList(int $eventSpecialtyId): Collection
    {
        return self::scopeToday(Appointment::query())
            ->where('event_specialty_id', $eventSpecialtyId)
            ->whereIn('status', self::DILATATION_STATUSES)
            ->orderBy('dilatation_sent_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Special case: the patient becomes a priority patient for the whole day.
     * Not yet checked in -> checked in now, starting with this specialty.
     * Already checked in -> this specialty becomes their active one, at the
     * head of the queue; the specialty they were in goes back on hold.
     */
    public function markSpecial(Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($appointment) {
            if (!$this->isToday($appointment)) {
                throw new RuleException('هذا الموعد ليس اليوم', 422);
            }

            $todays = $this->todaysAppointments($appointment->event_id, $appointment->patient_id);
            if ($appointment->status === 'Completed') {
                throw new RuleException('تم فحص الزائر في هذا التخصص مسبقاً', 422);
            }
            $notCheckedIn = $this->awaitsReception($todays);
            if (!$notCheckedIn && !$this->isOpen($appointment->event_specialty_id)) {
                throw new RuleException('هذا التخصص غير مفتوح اليوم، اختر تخصصاً مفتوحاً', 409);
            }

            Appointment::whereIn('id', $todays->pluck('id'))->update(['is_special' => true]);
            $appointment->refresh();

            // Not checked in yet: checked in now with priority (waits if nothing is open)
            if ($notCheckedIn) {
                return $this->checkIn($appointment->event_id, $appointment->patient_id, $appointment->id, true)
                    ?? $appointment->fresh();
            }

            if ($todays->contains(fn ($a) => $a->status === 'Entered')) {
                throw new RuleException('الزائر عند الطبيب حالياً، اضغط إجتاز أولاً', 422);
            }
            if ($todays->contains(fn ($a) => in_array($a->status, self::DILATATION_STATUSES, true))) {
                throw new RuleException('الزائر في التمدد حالياً، أنهِ التمدد أولاً', 422);
            }

            foreach ($todays as $other) {
                if ($other->id !== $appointment->id && in_array($other->status, self::QUEUE_STATUSES, true)) {
                    $this->setWaiting($other);
                }
            }

            $appointment->status = 'Present';
            $this->placeInQueue($appointment, null, true);

            return $appointment->fresh();
        });
    }

    /**
     * Super admin / special admin: the patient goes to place MOVE_UP_POSITION
     * (3) of this specialty's queue, or last if shorter, with no special-case mark
     * (nothing shows on screens). Not checked in yet -> checked in now.
     * Already checked in elsewhere -> this specialty becomes their active one.
     */
    public function moveToFront(Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($appointment) {
            if (!$this->isToday($appointment)) {
                throw new RuleException('هذا الموعد ليس اليوم', 422);
            }
            if (!$this->isOpen($appointment->event_specialty_id)) {
                throw new RuleException('هذا التخصص غير مفتوح اليوم، اختر تخصصاً مفتوحاً', 409);
            }

            $todays = $this->todaysAppointments($appointment->event_id, $appointment->patient_id);
            $appointment = $todays->firstWhere('id', $appointment->id);

            if ($appointment->status === 'Completed') {
                throw new RuleException('تم فحص الزائر في هذا التخصص مسبقاً', 422);
            }
            if ($todays->contains(fn ($a) => $a->status === 'Entered')) {
                throw new RuleException('الزائر عند الطبيب حالياً', 422);
            }
            if ($todays->contains(fn ($a) => in_array($a->status, self::DILATATION_STATUSES, true))) {
                throw new RuleException('الزائر في التمدد حالياً، أنهِ التمدد أولاً', 422);
            }

            if ($this->awaitsReception($todays)) {
                Appointment::whereIn('id', $todays->where('status', 'Pending')->pluck('id'))->update(['checked_in_at' => now()]);
            }
            foreach ($todays as $other) {
                if ($other->id !== $appointment->id && in_array($other->status, ['Pending', ...self::QUEUE_STATUSES], true)) {
                    $this->setWaiting($other);
                }
            }

            $this->lockQueue($appointment->event_specialty_id);
            $queue = $this->queue($appointment->event_specialty_id)
                ->reject(fn ($a) => $a->id === $appointment->id)
                ->values();

            $appointment->status = 'Present';
            $appointment->is_priority = false;
            $queue->splice(min(self::MOVE_UP_POSITION - 1, $queue->count()), 0, [$appointment]);
            foreach ($queue->values() as $i => $a) {
                $a->orderList = $i + 1;
                $a->save();
            }

            return $appointment->fresh();
        });
    }

    /**
     * Before an appointment is deleted: it leaves its queue (the gap closes)
     * and the patient's next specialty on hold becomes active.
     */
    public function beforeDelete(Appointment $appointment): void
    {
        if ($appointment->status === 'Entered') {
            throw new RuleException('الزائر عند الطبيب حالياً، لا يمكن حذف الموعد الآن', 422);
        }
        if (in_array($appointment->status, self::DILATATION_STATUSES, true)) {
            throw new RuleException('الزائر في التمدد حالياً، أنهِ التمدد أولاً', 422);
        }

        DB::transaction(function () use ($appointment) {
            if (in_array($appointment->status, self::QUEUE_STATUSES, true)) {
                $this->leaveQueue($appointment, 'Deleted');
                if ($this->isToday($appointment)) {
                    $this->promoteNext($appointment->event_id, $appointment->patient_id);
                }
            } elseif ($appointment->status === 'Completed') {
                $this->sendWaitingAfterCommit($appointment->event_specialty_id);
            }
        });
    }

    /**
     * After the super admin moved an appointment to another day/hour, or
     * added a specialty to a patient. A patient already checked in today
     * gets a new appointment for today on hold (or active if nothing else
     * is); any other appointment is simply booked (Pending).
     */
    public function afterSlotChange(Appointment $appointment): void
    {
        DB::transaction(function () use ($appointment) {
            $appointment->status = 'Pending';
            $appointment->orderList = 0;
            $appointment->is_priority = false;
            $appointment->checked_in_at = null;

            if (!$this->isToday($appointment)) {
                $appointment->save();
                return;
            }

            $others = $this->todaysAppointments($appointment->event_id, $appointment->patient_id)
                ->reject(fn ($a) => $a->id === $appointment->id);
            if ($others->isEmpty() || $this->awaitsReception($others)) {
                $appointment->save();
                return;
            }

            $appointment->checked_in_at = now();
            $appointment->status = 'Waiting';
            $appointment->save();
            $this->promoteNext($appointment->event_id, $appointment->patient_id);
        });
    }

    /**
     * "Send to another specialty" the patient did not book. They leave the
     * current queue now (it goes back on hold) and join the new specialty's
     * queue in 3rd place.
     */
    public function refer(Appointment $current, EventSpecialty $target): Appointment
    {
        return DB::transaction(function () use ($current, $target) {
            $this->assertStatus($current, [...self::QUEUE_STATUSES, 'Entered'], 'الزائر ليس في قائمة الانتظار');

            if ((int) $target->event_id !== (int) $current->event_id) {
                throw new RuleException('التخصص لا ينتمي لنفس الحدث', 422);
            }
            if (!$target->isOpenToday()) {
                throw new RuleException('هذا التخصص مغلق حالياً', 409);
            }
            if ($this->capacity($target->id)['full']) {
                throw new RuleException(self::FULL_MESSAGE, 409);
            }
            if (Appointment::where('event_id', $current->event_id)->where('patient_id', $current->patient_id)
                ->where('event_specialty_id', $target->id)->exists()) {
                throw new RuleException('الزائر مسجل في هذا التخصص مسبقاً', 409);
            }
            $this->assertNotBookedUnderOtherNumber($current, $target->id);

            [$day, $hour] = $this->todaysSlot($target);

            $new = Appointment::create([
                'user_id' => $current->user_id,
                'event_id' => $current->event_id,
                'event_specialty_id' => $target->id,
                'day_id' => $day->id,
                'hour_id' => $hour->id,
                'doctor_id' => null, // doctors share one room: not known before the visit
                'full_name' => $current->full_name,
                'birthday' => $current->birthday,
                'state' => $current->state,
                'city' => $current->city,
                'diseases' => $current->diseases,
                'phone' => $current->phone,
                'phone2' => $current->phone2,
                'sex' => $current->sex,
                'patient_id' => $current->patient_id,
                'specialty_order' => 0,
                'position' => $hour->counter + 1,
                'status' => 'Present',
                'is_special' => $current->is_special,
                'medical_documents' => $current->medical_documents,
            ]);
            $hour->increment('counter');
            $this->refreshSaturation($target);

            // Sent on by the doctor: this visit was not a completed examination
            $this->clearVisit($current);
            $this->setWaiting($current);
            $this->placeInQueue($new, self::TRANSFER_POSITION, $new->is_special);

            return $new->fresh();
        });
    }

    /**
     * «تبديل التخصص»: the patient moves to another specialty they booked for
     * today. The one they leave goes back on hold, so they return to it later
     * (at the end of its queue); no new appointment is created. They join the
     * end of the new queue, behind those already there; a special case still
     * goes to the priority block.
     */
    public function switchSpecialty(Appointment $current, int $targetId): Appointment
    {
        return DB::transaction(function () use ($current, $targetId) {
            $todays = $this->todaysAppointments($current->event_id, $current->patient_id);
            $target = $todays->firstWhere('id', $targetId);
            $current = $todays->firstWhere('id', $current->id);

            if (!$current || !$target || $current->id === $target->id) {
                throw new RuleException('هذا التخصص ليس من مواعيد الزائر اليوم', 422);
            }
            $this->assertStatus($current, [...self::QUEUE_STATUSES, 'Entered'], 'الزائر ليس في قائمة الانتظار');
            if ($blocker = $this->switchBlocker($target)) {
                throw new RuleException($blocker, 409);
            }

            // This visit was not a completed examination
            $this->clearVisit($current);
            $this->setWaiting($current);
            $target->status = 'Present';
            $this->placeInQueue($target, null, (bool) $target->is_special);

            return $target->fresh();
        });
    }

    /** Why a patient can't switch to this booked specialty now; null when they can. */
    public function switchBlocker(Appointment $target): ?string
    {
        if ($target->status === 'Completed') {
            return 'أنهى الفحص في هذا التخصص';
        }
        if (!$this->isToday($target)) {
            return 'موعده في يوم آخر';
        }
        if ($target->status !== 'Waiting') {
            return 'الزائر ليس في انتظار هذا التخصص';
        }
        if (!$this->isOpen($target->event_specialty_id)) {
            return 'هذا التخصص مغلق حالياً';
        }

        return $this->capacity($target->event_specialty_id)['full'] ? self::FULL_MESSAGE : null;
    }

    /**
     * Queue handling after reception transferred an appointment to another
     * specialty. Only today's appointments take part in the queue.
     */
    public function afterTransfer(Appointment $appointment, string $previousStatus, int $previousEventSpecialtyId): void
    {
        DB::transaction(function () use ($appointment, $previousStatus, $previousEventSpecialtyId) {
            $appointment->is_priority = false;

            // It left the old queue: the gap closes and the room goes to who waits there
            if (in_array($previousStatus, self::QUEUE_STATUSES, true)) {
                $this->renumberQueue($previousEventSpecialtyId);
            }
            if (in_array($previousStatus, self::LOAD_STATUSES, true)) {
                $this->sendWaitingAfterCommit($previousEventSpecialtyId);
            }

            if (!$this->isToday($appointment)) {
                $appointment->status = 'Pending';
                $appointment->orderList = 0;
                $appointment->save();
                // Moved to another day: the specialties on hold today go on without it
                $this->promoteNext($appointment->event_id, $appointment->patient_id);
                return;
            }

            $others = $this->todaysAppointments($appointment->event_id, $appointment->patient_id)
                ->reject(fn ($a) => $a->id === $appointment->id);
            $otherActive = $others->contains(fn ($a) => in_array($a->status, self::ACTIVE_STATUSES, true));
            $wasActive = in_array($previousStatus, self::QUEUE_STATUSES, true);

            if ($otherActive && !$wasActive) {
                $this->setWaiting($appointment);
                return;
            }

            // Not checked in yet: the transfer checks the patient in
            foreach ($others->where('status', 'Pending') as $other) {
                $this->setWaiting($other);
            }
            Appointment::whereIn('id', $others->pluck('id')->push($appointment->id))
                ->whereNull('checked_in_at')
                ->update(['checked_in_at' => now()]);

            // Closed (or full) specialty: it waits, the patient goes to an open one if any
            if (!$this->accepts($appointment->event_specialty_id)) {
                $this->setWaiting($appointment);
                $this->promoteNext($appointment->event_id, $appointment->patient_id);
                return;
            }

            $appointment->status = 'Present';
            $this->placeInQueue($appointment, self::TRANSFER_POSITION, false);
        });
    }

    // ------------------------------------------------ opening (super admin)

    /**
     * The doctor arrived: the specialty opens for today. Patients who wait
     * with no queue join it in arrival order, then patients from the back of
     * longer queues move to it while that brings them closer to the front.
     * The first PROTECTED_PLACES of a queue and priority patients stay.
     *
     * @return array{sent: int, moved: int}
     */
    public function openSpecialty(EventSpecialty $eventSpecialty): array
    {
        // Committed before sending: a check-in or «إجتاز» from now on sees it open
        DB::transaction(function () use ($eventSpecialty) {
            $this->lockQueue($eventSpecialty->id);
            $eventSpecialty->opened_on = self::today();
            $eventSpecialty->save();
        });

        $sent = $this->sendWaiting($eventSpecialty->id);

        return ['sent' => $sent, 'moved' => $this->moveFromLongerQueues($eventSpecialty)];
    }

    /**
     * Sends the patients waiting for this specialty with no queue elsewhere, in
     * arrival order, while it is open and not full. Runs whenever a specialty may
     * take someone it could not take before: nothing else looks at this list.
     *
     * @return int patients sent
     */
    public function sendWaiting(int $eventSpecialtyId): int
    {
        if (!$this->isOpen($eventSpecialtyId)) {
            return 0;
        }

        // Runs on every «إجتاز»: patients on hold behind another queue are left out here
        $elsewhere = self::scopeToday(Appointment::query())
            ->where('event_id', EventSpecialty::whereKey($eventSpecialtyId)->value('event_id'))
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->select('patient_id');
        $waiting = self::scopeToday(Appointment::query())
            ->where('event_specialty_id', $eventSpecialtyId)
            ->where('status', 'Waiting')
            ->whereNotIn('patient_id', $elsewhere)
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->get(['id', 'event_id', 'patient_id']);

        $sent = 0;
        foreach ($waiting as $row) {
            // One short transaction each: the desks keep working during a long list
            $result = DB::transaction(function () use ($row, $eventSpecialtyId) {
                $this->lockQueue($eventSpecialtyId);
                if (!$this->accepts($eventSpecialtyId)) {
                    return null;
                }
                $todays = $this->todaysAppointments($row->event_id, $row->patient_id);
                $appointment = $todays->firstWhere('id', $row->id);
                if ($appointment?->status !== 'Waiting'
                    || $todays->contains(fn ($a) => in_array($a->status, self::ACTIVE_STATUSES, true))) {
                    return false;
                }

                $appointment->status = 'Present';
                $this->placeInQueue($appointment, null, (bool) $appointment->is_special);

                return true;
            }, self::RETRIES);

            if ($result === null) {
                break;
            }
            $sent += (int) $result;
        }

        return $sent;
    }

    /** The event's schedule was edited: today's open specialties may have room now. */
    public function afterScheduleChange(int $eventId): void
    {
        EventSpecialty::where('event_id', $eventId)
            ->whereDate('opened_on', self::today())
            ->pluck('id')
            ->each(fn ($id) => $this->sendWaitingAfterCommit($id));
    }

    /** Patients at the back of longer queues move to a specialty that just opened. */
    private function moveFromLongerQueues(EventSpecialty $eventSpecialty): int
    {
        return DB::transaction(function () use ($eventSpecialty) {
            $this->lockQueue($eventSpecialty->id);

            $waiting = self::scopeToday(Appointment::query())
                ->where('event_specialty_id', $eventSpecialty->id)
                ->where('status', 'Waiting')
                ->orderBy('checked_in_at')
                ->orderBy('id')
                ->get();

            $candidates = collect(); // [waiting appointment here, active appointment elsewhere]
            foreach ($waiting as $appointment) {
                $active = self::scopeToday(Appointment::query())
                    ->where('event_id', $appointment->event_id)
                    ->where('patient_id', $appointment->patient_id)
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->first();

                if ($active && in_array($active->status, self::QUEUE_STATUSES, true) && !$active->is_priority) {
                    $candidates->push([$appointment, $active]);
                }
            }

            $moved = collect();
            while ($candidates->isNotEmpty()) {
                // The patient furthest back (longest queue on ties) moves first
                $ranked = $candidates
                    ->map(fn ($pair, $key) => [
                        'key' => $key,
                        'place' => $this->positionOf($pair[1]) ?? 0,
                        'length' => $this->queueLength($pair[1]->event_specialty_id),
                    ])
                    ->sortBy([['place', 'desc'], ['length', 'desc']])
                    ->first();

                $newPlace = $this->queueLength($eventSpecialty->id) + 1;
                if ($ranked['place'] <= max(self::PROTECTED_PLACES, $newPlace) || $this->capacity($eventSpecialty->id)['full']) {
                    break;
                }

                [$appointment, $active] = $candidates->pull($ranked['key']);
                $this->setWaiting($active);
                $appointment->status = 'Present';
                $this->placeInQueue($appointment, null, false);
                $moved->push($appointment);
            }

            // Moved patients take the last places among themselves in arrival order
            $places = $moved->pluck('orderList')->sort()->values();
            foreach ($moved->sortBy([['checked_in_at', 'asc'], ['id', 'asc']])->values() as $i => $appointment) {
                $appointment->orderList = $places[$i];
                $appointment->save();
            }

            return $moved->count();
        });
    }

    /** The doctor left: no new patients are sent; the current queue is still served. */
    public function closeSpecialty(EventSpecialty $eventSpecialty): void
    {
        $eventSpecialty->opened_on = null;
        $eventSpecialty->save();
    }

    // -------------------------------------------------------------- helpers

    /** Ids of the event's specialties opened today that still take patients (not full). */
    private function openSpecialtyIds(int $eventId): array
    {
        return EventSpecialty::where('event_id', $eventId)
            ->whereDate('opened_on', self::today())
            ->pluck('id')
            ->reject(fn ($id) => $this->capacity($id)['full'])
            ->values()
            ->all();
    }

    private function isOpen(int $eventSpecialtyId): bool
    {
        return EventSpecialty::whereKey($eventSpecialtyId)->whereDate('opened_on', self::today())->exists();
    }

    /** Open today and not full: new patients may be sent to it. */
    private function accepts(int $eventSpecialtyId): bool
    {
        return $this->isOpen($eventSpecialtyId) && !$this->capacity($eventSpecialtyId)['full'];
    }

    public const FULL_MESSAGE = 'هذا التخصص بلغ عدد الزوار المقرر للأطباء الحاضرين، يُفحص فقط من فيه';

    /**
     * Load against the visits planned for the doctors still present (a doctor
     * on a break is: left_at stays empty and they come back). Only
     * limited once a doctor of the specialty left today: then the patients in
     * it (queue, with a doctor, dilatation) plus those the present doctors
     * already saw may not exceed the visits planned for those doctors.
     *
     * @return array{limited: bool, full: bool, capacity: ?int, load: ?int}
     */
    public function capacity(int $eventSpecialtyId): array
    {
        $dayId = Day::where('event_specialty_id', $eventSpecialtyId)->whereDate('day_date', self::today())->value('id');
        $attendance = $dayId ? DoctorAttendance::where('day_id', $dayId)->whereNotNull('arrived_at')->get(['doctor_id', 'left_at']) : collect();
        $present = $attendance->whereNull('left_at')->pluck('doctor_id');

        if ($present->isEmpty() || $attendance->whereNotNull('left_at')->isEmpty()) {
            return ['limited' => false, 'full' => false, 'capacity' => null, 'load' => null];
        }

        $capacity = (int) DoctorInterval::where('day_id', $dayId)->whereIn('doctor_id', $present)->sum('visits_count');
        $load = self::scopeToday(Appointment::query())
            ->where('event_specialty_id', $eventSpecialtyId)
            ->where(fn ($q) => $q->whereIn('status', [...self::QUEUE_STATUSES, ...self::DILATATION_STATUSES, 'Entered'])
                ->orWhere(fn ($q) => $q->where('status', 'Completed')->whereIn('doctor_id', $present)))
            ->count();

        return ['limited' => true, 'full' => $load >= $capacity, 'capacity' => $capacity, 'load' => $load];
    }

    private function queueLength(int $eventSpecialtyId): int
    {
        return self::scopeToday(Appointment::query())
            ->where('event_specialty_id', $eventSpecialtyId)
            ->whereIn('status', self::QUEUE_STATUSES)
            ->count();
    }

    /**
     * The candidate whose open specialty has the shortest queue. Candidates
     * come primary specialty first, which wins ties (stable sort).
     */
    private function awaitsReception(Collection $todays): bool
    {
        return $todays->contains('status', 'Pending')
            && $todays->every(fn ($a) => in_array($a->status, ['Pending', 'Completed'], true));
    }

    private function pickLeastBusy(Collection $candidates, array $openIds): ?Appointment
    {
        return $candidates
            ->filter(fn ($a) => in_array($a->event_specialty_id, $openIds))
            ->sortBy(fn ($a) => $this->queueLength($a->event_specialty_id))
            ->first();
    }

    /** Next appointment on hold today becomes active and joins the least busy open queue. */
    private function promoteNext(int $eventId, int $patientId, bool $retry = true): ?Appointment
    {
        $todays = $this->todaysAppointments($eventId, $patientId);

        if ($todays->contains(fn ($a) => in_array($a->status, self::ACTIVE_STATUSES, true))) {
            return null;
        }

        $next = $this->pickLeastBusy($todays->where('status', 'Waiting'), $this->openSpecialtyIds($eventId));
        if (!$next) {
            if ($retry && $todays->contains('status', 'Waiting')) {
                $this->promoteAfterCommit($eventId, $patientId);
            }

            return null;
        }

        $next->status = 'Present';
        $this->placeInQueue($next, null, (bool) $next->is_special);

        return $next->fresh();
    }

    /**
     * Inserts the appointment into its queue and renumbers it.
     * $position is 1-based (null = end). Priority patients go after the
     * priority patients already waiting; a fixed position never jumps
     * ahead of the priority block.
     */
    private function placeInQueue(Appointment $appointment, ?int $position, bool $priority): void
    {
        $this->lockQueue($appointment->event_specialty_id);

        $queue = $this->queue($appointment->event_specialty_id)
            ->reject(fn ($a) => $a->id === $appointment->id)
            ->values();
        $afterPriority = $this->lastPriorityIndex($queue) + 1;

        if ($priority) {
            $index = $afterPriority;
        } elseif ($position !== null) {
            $index = min(max($position - 1, $afterPriority), $queue->count());
        } else {
            $index = $queue->count();
        }

        $appointment->is_priority = $priority;
        $queue->splice($index, 0, [$appointment]);

        foreach ($queue->values() as $i => $a) {
            $a->orderList = $i + 1;
            $a->save();
        }
    }

    /** Index of the last priority patient in an ordered queue, -1 when none. */
    private function lastPriorityIndex(Collection $queue): int
    {
        $last = -1;
        foreach ($queue->values() as $i => $a) {
            if ($a->is_priority) {
                $last = $i;
            }
        }

        return $last;
    }

    private function leaveQueue(Appointment $appointment, string $status): void
    {
        $previous = $appointment->getOriginal('status');

        $appointment->status = $status;
        $appointment->orderList = 0;
        $appointment->is_priority = false;
        $appointment->save();

        if (in_array($previous, self::QUEUE_STATUSES, true)) {
            $this->renumberQueue($appointment->event_specialty_id);
        }
        // One patient less in a full specialty: someone waiting may take the place.
        // Completed too: the visits of a doctor who left don't count against capacity
        if (in_array($previous, self::LOAD_STATUSES, true) && ($status === 'Completed' || !in_array($status, self::LOAD_STATUSES, true))) {
            $this->sendWaitingAfterCommit($appointment->event_specialty_id);
        }
    }

    /** Closes the gap so the remaining patients keep places 1, 2, 3 ... */
    private function renumberQueue(int $eventSpecialtyId): void
    {
        $this->lockQueue($eventSpecialtyId);
        foreach ($this->queue($eventSpecialtyId)->values() as $i => $a) {
            $a->orderList = $i + 1;
            $a->save();
        }
    }

    private function sendWaitingAfterCommit(int $eventSpecialtyId): void
    {
        $this->afterCommit(fn () => $this->sendWaiting($eventSpecialtyId));
    }

    /**
     * A specialty may be opening right now, not visible to this transaction,
     * and its sending may have run before this patient started waiting: neither
     * side would send them. Looking again once committed closes that gap.
     */
    private function promoteAfterCommit(int $eventId, int $patientId): void
    {
        $this->afterCommit(fn () => DB::transaction(fn () => $this->promoteNext($eventId, $patientId, false), self::RETRIES));
    }

    /** The staff action is already saved: a failure here is logged, not shown as its failure. */
    private function afterCommit(callable $work): void
    {
        DB::afterCommit(function () use ($work) {
            try {
                $work();
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    private function clearVisit(Appointment $appointment): void
    {
        $appointment->doctor_id = null;
        $appointment->entered_at = null;
        $appointment->completed_at = null;
        $appointment->visit_group = null;
        $appointment->visit_seconds = null;
    }

    private function setWaiting(Appointment $appointment): void
    {
        $this->leaveQueue($appointment, 'Waiting');
    }

    /** Serializes changes to one queue (two officers clicking at once). */
    private function lockQueue(int $eventSpecialtyId): void
    {
        EventSpecialty::whereKey($eventSpecialtyId)->lockForUpdate()->first();
    }

    private function assertStatus(Appointment $appointment, array $allowed, string $message): void
    {
        if (!in_array($appointment->status, $allowed, true)) {
            throw new RuleException($message, 422);
        }
    }

    /** Today's day of a specialty and its hour slot closest to now. */
    public function todaysSlot(EventSpecialty $eventSpecialty): array
    {
        $day = Day::where('event_specialty_id', $eventSpecialty->id)
            ->whereDate('day_date', self::today())
            ->first();

        if (!$day) {
            throw new RuleException('هذا التخصص لا يعمل اليوم', 409);
        }

        $now = Carbon::now(self::TIMEZONE)->format('H:i:s');
        $hour = Hour::where('day_id', $day->id)->where('time', '<=', $now)->orderByDesc('time')->lockForUpdate()->first()
            ?? Hour::where('day_id', $day->id)->orderBy('time')->lockForUpdate()->first();

        if (!$hour) {
            throw new RuleException('لا توجد ساعات عمل لهذا التخصص اليوم', 409);
        }

        return [$day, $hour];
    }

    private function refreshSaturation(EventSpecialty $eventSpecialty): void
    {
        $hasRoom = Hour::whereIn('day_id', Day::where('event_specialty_id', $eventSpecialty->id)->pluck('id'))
            ->whereColumn('counter', '<', 'max_allowed')
            ->exists();

        if ($eventSpecialty->is_saturated === $hasRoom) {
            $eventSpecialty->update(['is_saturated' => !$hasRoom]);
        }
    }

    /**
     * The same person (name + birthday) registered under another patient
     * number already holds this specialty: the two registrations must be
     * merged at the presence desk first.
     *
     * @throws RuleException
     */
    public function assertNotBookedUnderOtherNumber(Appointment $appointment, int $eventSpecialtyId): void
    {
        $other = Appointment::where('event_id', $appointment->event_id)
            ->where('event_specialty_id', $eventSpecialtyId)
            ->where('identity_key', Appointment::identityKey($appointment->full_name, $appointment->birthday))
            ->value('patient_id');

        if ($other) {
            throw new RuleException("نفس الشخص مسجل في هذا التخصص برقم آخر #{$other}، ادمج التسجيلين في مكتب الحضور", 409);
        }
    }

    /**
     * Two registrations merged into one patient can exceed the event's
     * specialty limit or hold one specialty twice: an admin deletes the extra
     * appointments before the patient can be checked in.
     */
    private function assertWithinLimit(int $eventId, int $patientId): void
    {
        $specialties = Appointment::where('event_id', $eventId)->where('patient_id', $patientId)->pluck('event_specialty_id');
        // Appointments booked past the limit by an allowed account are not counted
        $counted = Appointment::where('event_id', $eventId)->where('patient_id', $patientId)->where('beyond_limit', false)->count();
        $limit = max(1, (int) Event::whereKey($eventId)->value('nbr_of_allowed_specialties'));

        if ($counted > $limit) {
            throw new RuleException("للزائر {$counted} مواعيد والحدث يسمح بـ {$limit}. يحذف المشرف الزائد قبل تسجيل الحضور", 422);
        }
        if ($specialties->duplicates()->isNotEmpty()) {
            throw new RuleException('الزائر حاجز نفس التخصص مرتين. يحذف المشرف أحد الموعدين قبل تسجيل الحضور', 422);
        }
    }

    private function failNoAppointmentToday(int $eventId, int $patientId): void
    {
        $dates = Appointment::where('event_id', $eventId)
            ->where('patient_id', $patientId)
            ->join('days', 'days.id', '=', 'appointments.day_id')
            ->orderBy('days.day_date')
            ->pluck('days.day_date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            throw new RuleException('لا يوجد زائر بهذا الرقم في هذا الحدث', 404);
        }

        throw new RuleException('ليس للزائر موعد اليوم. مواعيده: ' . $dates->implode('، '), 422);
    }
}
