<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Day;
use App\Models\EventSpecialty;
use App\Models\Hour;
use App\Exceptions\RuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All visit-day queue rules live here.
 *
 * - A patient only takes part in the appointments booked for TODAY.
 * - A patient is active in one specialty at a time (Present, Pushed down or
 *   Dilatation); their other appointments today wait as "Waiting".
 * - A specialty queue = today's Present + Commingsoon appointments, ordered by
 *   orderList (1, 2, 3 ...). Priority patients (special case, back from
 *   dilatation) form a block at the head, in the order they arrived.
 * - A specialty only receives patients once the super admin opened it today
 *   (its doctor is there). A patient goes to the least busy open specialty
 *   they booked; with none open they wait until one opens.
 */
class QueueService
{
    public const TIMEZONE = 'Africa/Algiers';
    public const QUEUE_STATUSES = ['Present', 'Commingsoon'];
    // Dilatation is a hidden station of a specialty (تصحيح النظر): the patient is
    // sent there (DilatationSent), received by the dilatation officer (Dilatation),
    // then returns to the head of the specialty's queue.
    public const DILATATION_STATUSES = ['DilatationSent', 'Dilatation'];
    public const ACTIVE_STATUSES = ['Present', 'Commingsoon', 'DilatationSent', 'Dilatation'];
    public const PUSH_DOWN_PLACES = 5;
    public const TRANSFER_POSITION = 3;
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

            if ($todays->contains(fn ($a) => $a->status !== 'Pending')) {
                throw new RuleException('المريض مسجل حضوره اليوم مسبقاً', 409);
            }

            Appointment::whereIn('id', $todays->pluck('id'))->update(['checked_in_at' => now()]);

            $special = $priority || $todays->contains('is_special', true);
            $open = $this->openSpecialtyIds($eventId);
            $preferred = $firstAppointmentId ? $todays->firstWhere('id', $firstAppointmentId) : null;
            $first = $preferred && in_array($preferred->event_specialty_id, $open)
                ? $preferred
                : $this->pickLeastBusy($todays, $open);

            foreach ($todays as $other) {
                if ($other->id !== $first?->id) {
                    $this->setWaiting($other);
                }
            }

            if (!$first) {
                return null;
            }

            $first->status = 'Present';
            $this->placeInQueue($first, null, $special);

            return $first->fresh();
        });
    }

    // ------------------------------------------------- specialty officer

    /** "Passed": examination done; the patient moves on to their next specialty. */
    public function complete(Appointment $appointment): ?Appointment
    {
        return DB::transaction(function () use ($appointment) {
            $this->leaveQueue($appointment, 'Completed');

            return $this->promoteNext($appointment->event_id, $appointment->patient_id);
        });
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
        $this->assertStatus($appointment, self::QUEUE_STATUSES, 'المريض ليس في قائمة الانتظار');
        if (!EventSpecialty::whereKey($appointment->event_specialty_id)->value('has_dilatation')) {
            throw new RuleException('هذا التخصص ليس له قاعة تمدد', 422);
        }

        DB::transaction(function () use ($appointment) {
            $appointment->dilatation_sent_at = now();
            $appointment->dilatation_started_at = null;
            $this->leaveQueue($appointment, 'DilatationSent');
        });
    }

    /** Dilatation officer: the patient arrived in the dilatation room (timer starts). */
    public function receiveInDilatation(Appointment $appointment): void
    {
        $this->assertStatus($appointment, ['DilatationSent'], 'المريض ليس في الطريق إلى التمدد');
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
        $this->assertStatus($appointment, self::DILATATION_STATUSES, 'المريض ليس في التمدد');
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
            $notCheckedIn = $todays->every(fn ($a) => $a->status === 'Pending');
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

            if ($appointment->status === 'Completed') {
                throw new RuleException('تم فحص المريض في هذا التخصص مسبقاً', 422);
            }
            if ($todays->contains(fn ($a) => in_array($a->status, self::DILATATION_STATUSES, true) && $a->id !== $appointment->id)) {
                throw new RuleException('المريض في التمدد حالياً، أنهِ التمدد أولاً', 422);
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
     * "Send to another specialty" the patient did not book. They leave the
     * current queue now (it goes back on hold) and join the new specialty's
     * queue in 3rd place.
     */
    public function refer(Appointment $current, EventSpecialty $target): Appointment
    {
        return DB::transaction(function () use ($current, $target) {
            $this->assertStatus($current, self::QUEUE_STATUSES, 'المريض ليس في قائمة الانتظار');

            if ((int) $target->event_id !== (int) $current->event_id) {
                throw new RuleException('التخصص لا ينتمي لنفس الحدث', 422);
            }
            if (!$target->isOpenToday()) {
                throw new RuleException('هذا التخصص مغلق حالياً', 409);
            }
            if (Appointment::where('event_id', $current->event_id)->where('patient_id', $current->patient_id)
                ->where('event_specialty_id', $target->id)->exists()) {
                throw new RuleException('المريض مسجل في هذا التخصص مسبقاً', 409);
            }

            [$day, $hour] = $this->todaysSlot($target);

            $new = Appointment::create([
                'user_id' => $current->user_id,
                'event_id' => $current->event_id,
                'event_specialty_id' => $target->id,
                'day_id' => $day->id,
                'hour_id' => $hour->id,
                'doctor_id' => $target->doctors()->value('doctors.id'),
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

            $this->setWaiting($current);
            $this->placeInQueue($new, self::TRANSFER_POSITION, $new->is_special);

            return $new->fresh();
        });
    }

    /**
     * Queue handling after reception transferred an appointment to another
     * specialty. Only today's appointments take part in the queue.
     */
    public function afterTransfer(Appointment $appointment, string $previousStatus): void
    {
        DB::transaction(function () use ($appointment, $previousStatus) {
            $appointment->is_priority = false;

            if (!$this->isToday($appointment)) {
                $appointment->status = 'Pending';
                $appointment->orderList = 0;
                $appointment->save();
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

            // Closed specialty: it waits, the patient goes to an open one if any
            if (!$this->isOpen($appointment->event_specialty_id)) {
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
        return DB::transaction(function () use ($eventSpecialty) {
            $this->lockQueue($eventSpecialty->id);
            $eventSpecialty->opened_on = self::today();
            $eventSpecialty->save();

            $waiting = self::scopeToday(Appointment::query())
                ->where('event_specialty_id', $eventSpecialty->id)
                ->where('status', 'Waiting')
                ->orderBy('checked_in_at')
                ->orderBy('id')
                ->get();

            $sent = 0;
            $candidates = collect(); // [waiting appointment here, active appointment elsewhere]
            foreach ($waiting as $appointment) {
                $active = self::scopeToday(Appointment::query())
                    ->where('event_id', $appointment->event_id)
                    ->where('patient_id', $appointment->patient_id)
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->first();

                if (!$active) {
                    $appointment->status = 'Present';
                    $this->placeInQueue($appointment, null, (bool) $appointment->is_special);
                    $sent++;
                } elseif (in_array($active->status, self::QUEUE_STATUSES, true) && !$active->is_priority) {
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
                if ($ranked['place'] <= max(self::PROTECTED_PLACES, $newPlace)) {
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

            return ['sent' => $sent, 'moved' => $moved->count()];
        });
    }

    /** The doctor left: no new patients are sent; the current queue is still served. */
    public function closeSpecialty(EventSpecialty $eventSpecialty): void
    {
        $eventSpecialty->opened_on = null;
        $eventSpecialty->save();
    }

    // -------------------------------------------------------------- helpers

    /** Ids of the event's specialties opened today. */
    private function openSpecialtyIds(int $eventId): array
    {
        return EventSpecialty::where('event_id', $eventId)
            ->whereDate('opened_on', self::today())
            ->pluck('id')
            ->all();
    }

    private function isOpen(int $eventSpecialtyId): bool
    {
        return EventSpecialty::whereKey($eventSpecialtyId)->whereDate('opened_on', self::today())->exists();
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
    private function pickLeastBusy(Collection $candidates, array $openIds): ?Appointment
    {
        return $candidates
            ->filter(fn ($a) => in_array($a->event_specialty_id, $openIds))
            ->sortBy(fn ($a) => $this->queueLength($a->event_specialty_id))
            ->first();
    }

    /** Next appointment on hold today becomes active and joins the least busy open queue. */
    private function promoteNext(int $eventId, int $patientId): ?Appointment
    {
        $todays = $this->todaysAppointments($eventId, $patientId);

        if ($todays->contains(fn ($a) => in_array($a->status, self::ACTIVE_STATUSES, true))) {
            return null;
        }

        $next = $this->pickLeastBusy($todays->where('status', 'Waiting'), $this->openSpecialtyIds($eventId));
        if (!$next) {
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
        $wasQueued = in_array($appointment->getOriginal('status'), self::QUEUE_STATUSES, true);

        $appointment->status = $status;
        $appointment->orderList = 0;
        $appointment->is_priority = false;
        $appointment->save();

        // Close the gap so the remaining patients keep places 1, 2, 3 ...
        if ($wasQueued) {
            $this->lockQueue($appointment->event_specialty_id);
            foreach ($this->queue($appointment->event_specialty_id)->values() as $i => $a) {
                $a->orderList = $i + 1;
                $a->save();
            }
        }
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
    private function todaysSlot(EventSpecialty $eventSpecialty): array
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

    /** @throws RuleException */
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
            throw new RuleException('لا يوجد مريض بهذا الرقم في هذا الحدث', 404);
        }

        throw new RuleException('ليس للمريض موعد اليوم. مواعيده: ' . $dates->implode('، '), 422);
    }
}
