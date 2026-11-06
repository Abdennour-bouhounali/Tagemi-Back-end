<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Day;
use App\Models\DoctorAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Visit day, per doctor: who is scheduled and present today, who is with a
 * patient now, how many patients each doctor saw and how long a visit lasts.
 * A visit lasting more than ALARM_FACTOR x the planned visit time raises an
 * alarm; a present doctor free for IDLE_MINUTES while patients wait is flagged.
 */
class DoctorVisitStats
{
    public const ALARM_FACTOR = 1.5;
    public const IDLE_MINUTES = 10;

    /** Today's doctors of the given event specialties (all of the event when null), keyed by event specialty id. */
    public function forEvent(int $eventId, ?array $eventSpecialtyIds = null): Collection
    {
        $days = Day::with(['intervals.doctor', 'eventSpecialty.specialty'])
            ->whereHas('eventSpecialty', fn ($q) => $q->where('event_id', $eventId))
            ->when($eventSpecialtyIds !== null, fn ($q) => $q->whereIn('event_specialty_id', $eventSpecialtyIds))
            ->whereDate('day_date', QueueService::today())
            ->get();

        $attendance = DoctorAttendance::with('periods')->whereIn('day_id', $days->pluck('id'))->get()
            ->keyBy(fn ($a) => $a->day_id . '-' . $a->doctor_id);

        $visits = QueueService::scopeToday(Appointment::query())
            ->where('event_id', $eventId)
            ->whereNotNull('doctor_id')
            ->whereIn('status', ['Entered', 'Completed'])
            ->get(['id', 'patient_id', 'full_name', 'doctor_id', 'event_specialty_id', 'status', 'entered_at', 'completed_at', 'visit_group', 'visit_seconds'])
            ->groupBy('doctor_id');

        $queueLengths = QueueService::scopeToday(Appointment::query())
            ->where('event_id', $eventId)
            ->whereIn('status', QueueService::QUEUE_STATUSES)
            ->selectRaw('event_specialty_id, COUNT(*) as c')
            ->groupBy('event_specialty_id')
            ->pluck('c', 'event_specialty_id');

        $now = Carbon::now();

        return $days->mapWithKeys(fn (Day $day) => [
            $day->event_specialty_id => $day->intervals->groupBy('doctor_id')->map(
                fn ($intervals, $doctorId) => $this->doctorRow(
                    (int) $doctorId,
                    $day,
                    $intervals,
                    $attendance[$day->id . '-' . $doctorId] ?? null,
                    $visits->get($doctorId, collect()),
                    (int) ($queueLengths[$day->event_specialty_id] ?? 0),
                    $now,
                )
            )->sortBy('full_name')->values(),
        ]);
    }

    private function doctorRow(int $doctorId, Day $day, Collection $intervals, ?DoctorAttendance $attendance,
        Collection $visits, int $queueLength, Carbon $now): array
    {
        $planned = $this->plannedMinutes($intervals, $now);
        $completed = $visits->where('status', 'Completed');
        $timed = $completed->whereNotNull('visit_seconds');
        $inside = $visits->where('status', 'Entered')->values();
        $present = (bool) $attendance?->isWorking();

        $current = null;
        if ($inside->isNotEmpty()) {
            // Relatives who already passed alone still count: same timer, same allowance
            $group = $inside->first()->visit_group;
            $visit = $group ? $visits->where('visit_group', $group) : $inside;
            $enteredAt = $visit->min('entered_at');
            $elapsed = $enteredAt ? (int) $enteredAt->diffInSeconds($now, true) : 0;
            $alarmAfter = $planned ? (int) round(self::ALARM_FACTOR * $planned * 60 * $visit->count()) : null;
            $current = [
                'visit_group' => $inside->first()->visit_group,
                'entered_at' => $enteredAt?->toIso8601String(),
                'elapsed_seconds' => $elapsed,
                'alarm_after_seconds' => $alarmAfter,
                'alarm' => $alarmAfter !== null && $elapsed > $alarmAfter,
                'patients' => $inside->map(fn ($a) => $a->only(['id', 'patient_id', 'full_name']))->values(),
            ];
        }

        // Free since the last visit ended (or since coming in, a break doesn't count) while patients wait
        $idleSince = null;
        if ($present && !$current) {
            $presentSince = $attendance->presentSince();
            $idleSince = $completed->max('completed_at');
            if (!$idleSince || $idleSince->lt($presentSince)) {
                $idleSince = $presentSince;
            }
        }
        $idleMinutes = $idleSince ? intdiv((int) $idleSince->diffInSeconds($now, true), 60) : null;

        return [
            'doctor_id' => $doctorId,
            'full_name' => $intervals->first()->doctor?->full_name,
            'event_specialty_id' => $day->event_specialty_id,
            'specialty' => $day->eventSpecialty?->specialty?->name,
            'intervals' => $intervals->sortBy('start_time')
                ->map(fn ($iv) => substr($iv->start_time, 0, 5) . '–' . substr($iv->end_time, 0, 5))->values(),
            'planned_visits' => (int) $intervals->sum('visits_count'),
            'planned_end' => substr((string) $intervals->max('end_time'), 0, 5),
            'planned_minutes' => $planned,
            'arrived_at' => $this->clock($attendance?->arrived_at),
            'left_at' => $this->clock($attendance?->left_at),
            'present_since' => $this->clock($attendance?->presentSince()),
            'periods' => $attendance?->periodClocks() ?? [],
            'present' => $present,
            'on_break' => (bool) $attendance?->isOnBreak(),
            'on_break_since' => $this->clock($attendance?->on_break_since),
            'treated' => $completed->count(),
            'mean_minutes' => $timed->isEmpty() ? null : round($timed->avg('visit_seconds') / 60, 1),
            'mean_seconds' => $timed->isEmpty() ? null : (int) round($timed->avg('visit_seconds')),
            'busy' => $current !== null,
            'current' => $current,
            'idle_minutes' => $idleMinutes,
            'idle' => $idleMinutes !== null && $idleMinutes >= self::IDLE_MINUTES && $queueLength > 0,
        ];
    }

    /**
     * Visit durations of today's finished visits: the mean against the planned
     * visit time, the fastest and slowest specialty (by mean), and per specialty and per
     * doctor the mean and the time the remaining patients would be done at the
     * same pace. Remaining = today's appointments not finished yet (not arrived
     * ones included). A specialty's doctors share one queue, so it ends at
     * remaining / sum(1 / mean of each working doctor); a doctor's own estimate
     * assumes the remaining patients are split equally between them.
     * Working doctors = present ones and those on a break, or all planned ones
     * before anyone arrived.
     * A doctor with no finished visit yet counts at the planned visit time.
     *
     * @param Collection $doctors forEvent() rows, keyed by event specialty id
     */
    public function timing(int $eventId, Collection $doctors): array
    {
        $visits = QueueService::scopeToday(Appointment::query())
            ->where('event_id', $eventId)
            ->where('status', 'Completed')
            ->whereNotNull('doctor_id')
            ->whereNotNull('visit_seconds')
            ->get(['id', 'doctor_id', 'event_specialty_id', 'visit_seconds', 'completed_at']);

        $remaining = QueueService::scopeToday(Appointment::query())
            ->where('event_id', $eventId)
            ->where('status', '!=', 'Completed')
            ->selectRaw("event_specialty_id, COUNT(*) as total, SUM(status = 'Pending') as pending")
            ->groupBy('event_specialty_id')
            ->get()
            ->keyBy('event_specialty_id');

        $now = Carbon::now();
        $planned = $doctors->flatten(1)->keyBy(fn ($d) => $d['event_specialty_id'] . '-' . $d['doctor_id']);
        $plannedOf = fn ($v) => $planned[$v->event_specialty_id . '-' . $v->doctor_id]['planned_minutes'] ?? null;
        $withPlan = $visits->filter(fn ($v) => $plannedOf($v));

        $specialties = [];
        $doctorRows = [];
        foreach ($doctors as $eventSpecialtyId => $rows) {
            $left = $remaining[$eventSpecialtyId] ?? null;
            $count = (int) ($left->total ?? 0);
            $working = $rows->filter(fn ($d) => $d['present'] || $d['on_break']);
            if ($working->isEmpty() && $rows->where('treated', '>', 0)->isEmpty() && $rows->whereNotNull('left_at')->isEmpty()) {
                $working = $rows; // nobody arrived yet: the planned doctors
            }
            $pace = fn ($d) => $d['mean_seconds'] ?? ($d['planned_minutes'] ? $d['planned_minutes'] * 60 : null);
            $rate = $working->sum(fn ($d) => $pace($d) ? 1 / $pace($d) : 0);
            $specialtyVisits = $visits->where('event_specialty_id', $eventSpecialtyId);

            $specialties[] = [
                'event_specialty_id' => $eventSpecialtyId,
                'specialty' => $rows->first()['specialty'] ?? null,
                'doctors' => $rows->count(),
                'working' => $working->count(),
                'treated' => $specialtyVisits->count(),
                'mean_seconds' => $specialtyVisits->isEmpty() ? null : (int) round($specialtyVisits->avg('visit_seconds')),
                'fastest_seconds' => $specialtyVisits->min('visit_seconds'),
                'longest_seconds' => $specialtyVisits->max('visit_seconds'),
                'planned_minutes' => $rows->pluck('planned_minutes')->filter()->avg(),
                'remaining' => $count,
                'remaining_pending' => (int) ($left->pending ?? 0),
                'estimated_end' => $this->endAt($now, $count, $rate),
                'planned_end' => $rows->max('planned_end'),
            ];

            foreach ($rows as $d) {
                $works = $working->contains(fn ($w) => $w['doctor_id'] === $d['doctor_id']);
                $doctorRows[] = $d + [
                    'working' => $works,
                    'estimated_end' => $works && $pace($d) ? $this->endAt($now, $count / $working->count(), 1 / $pace($d)) : null,
                ];
            }
        }

        return [
            'treated' => $visits->count(),
            'mean_seconds' => $visits->isEmpty() ? null : (int) round($visits->avg('visit_seconds')),
            // Planned visit time of the doctors who did these visits, weighted by visits
            'planned_mean_seconds' => $withPlan->isEmpty() ? null : (int) round($withPlan->avg(fn ($v) => $plannedOf($v) * 60)),
            'planned_mean_compared_on' => $withPlan->count(),
            'fastest_specialty' => $this->bySpeed($specialties)->first(),
            'longest_specialty' => $this->bySpeed($specialties)->last(),
            'specialties' => collect($specialties)->sortBy('specialty')->values(),
            'doctors' => collect($doctorRows)->sortBy([['specialty', 'asc'], ['full_name', 'asc']])->values(),
        ];
    }

    /** Specialties with finished visits, fastest mean first. */
    private function bySpeed(array $specialties): Collection
    {
        return collect($specialties)->whereNotNull('mean_seconds')->sortBy('mean_seconds')->values()
            ->map(fn ($s) => collect($s)->only(['specialty', 'mean_seconds', 'treated', 'fastest_seconds', 'longest_seconds'])->all());
    }

    /** Clock time when $count visits are done at $rate visits per second; now when nothing is left. */
    private function endAt(Carbon $now, float $count, float $rate): ?string
    {
        if ($count <= 0) {
            return $this->clock($now);
        }

        return $rate > 0 ? $this->clock($now->copy()->addSeconds((int) ceil($count / $rate))) : null;
    }

    /** Visit minutes of the interval running now, else the next one, else the last one. */
    private function plannedMinutes(Collection $intervals, Carbon $now): ?int
    {
        $clock = $now->copy()->setTimezone(QueueService::TIMEZONE)->format('H:i:s');
        $sorted = $intervals->sortBy('start_time')->values();
        $interval = $sorted->first(fn ($iv) => $iv->start_time <= $clock && $clock < $iv->end_time)
            ?? $sorted->first(fn ($iv) => $iv->start_time > $clock)
            ?? $sorted->last();

        return $interval?->visit_minutes ?: null;
    }

    private function clock($time): ?string
    {
        return $time?->copy()->setTimezone(QueueService::TIMEZONE)->format('H:i');
    }
}
