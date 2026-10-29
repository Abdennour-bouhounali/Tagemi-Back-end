<?php

namespace App\Services;

use App\Exceptions\RuleException;
use App\Models\Appointment;
use App\Models\Day;
use App\Models\Doctor;
use App\Models\DoctorInterval;
use App\Models\EventSpecialty;
use App\Models\Hour;

/**
 * Doctors' working intervals of a specialty, and the bookable hours computed
 * from them. Patients book an hour and all doctors of a specialty work in the
 * same room, so an hour's capacity is the number of visits, of all doctors,
 * that start within it. Example: 08:30-10:00 with 20-minute visits gives
 * 08:30, 08:50 (hour 08) and 09:10, 09:30 (hour 09).
 *
 * A schedule (API payload of one specialty) is:
 *   [{ doctor_id, days: [{ day_date, intervals: [{ id?, start_time, end_time, visit_minutes }] }] }]
 */
class DoctorScheduleService
{
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    public static function time(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    public static function visits(int $start, int $end, int $visitMinutes): int
    {
        return $visitMinutes > 0 ? max(0, intdiv($end - $start, $visitMinutes)) : 0;
    }

    /**
     * @param iterable $intervals items with start_time, end_time, visit_minutes
     * @return array<string,int> 'HH:00:00' => visits starting in that hour, sorted
     */
    public static function hourlyCapacity(iterable $intervals): array
    {
        $capacity = [];
        foreach ($intervals as $interval) {
            $start = self::minutes($interval['start_time']);
            $visit = (int) $interval['visit_minutes'];
            $count = self::visits($start, self::minutes($interval['end_time']), $visit);
            for ($k = 0; $k < $count; $k++) {
                $hour = self::time(intdiv($start + $k * $visit, 60) * 60);
                $capacity[$hour] = ($capacity[$hour] ?? 0) + 1;
            }
        }
        ksort($capacity);

        return $capacity;
    }

    /**
     * Refuses a schedule that cannot be saved, with a message for the admin.
     * $keptDoctorIds: doctors already in this event specialty, kept even if
     * they were deactivated since.
     *
     * @throws RuleException
     */
    public function validate(int $specialtyId, array $schedule, array $keptDoctorIds = []): void
    {
        $doctorIds = array_map(fn ($d) => (int) $d['doctor_id'], $schedule);
        if (count($doctorIds) !== count(array_unique($doctorIds))) {
            throw new RuleException('أُضيف نفس الطبيب مرتين في التخصص');
        }

        $doctors = Doctor::whereIn('id', $doctorIds)->get()->keyBy('id');

        foreach ($schedule as $entry) {
            $doctor = $doctors[(int) $entry['doctor_id']] ?? null;
            if (!$doctor) {
                throw new RuleException('طبيب غير موجود');
            }
            $name = $doctor->full_name;
            if ((int) $doctor->specialty_id !== $specialtyId) {
                throw new RuleException("الطبيب {$name} ليس من هذا التخصص");
            }
            if (!$doctor->is_active && !in_array($doctor->id, $keptDoctorIds, true)) {
                throw new RuleException("الطبيب {$name} موقوف");
            }

            $dates = array_map(fn ($d) => date('Y-m-d', strtotime($d['day_date'])), $entry['days']);
            if (count($dates) !== count(array_unique($dates))) {
                throw new RuleException("نفس اليوم مكرر للطبيب {$name}، اجمع فتراته في يوم واحد");
            }

            foreach ($entry['days'] as $i => $day) {
                $intervals = collect($day['intervals'])
                    ->map(fn ($iv) => [self::minutes($iv['start_time']), self::minutes($iv['end_time']), (int) $iv['visit_minutes']])
                    ->sortBy(0)->values();

                foreach ($intervals as $j => [$start, $end, $visit]) {
                    $label = substr(self::time($start), 0, 5) . ' - ' . substr(self::time($end), 0, 5);
                    if ($end <= $start) {
                        throw new RuleException("فترة {$label} للطبيب {$name} ({$dates[$i]}): وقت النهاية قبل البداية");
                    }
                    if (self::visits($start, $end, $visit) < 1) {
                        throw new RuleException("فترة {$label} للطبيب {$name} ({$dates[$i]}) أقصر من مدة زيارة واحدة");
                    }
                    if ($j > 0 && $start < $intervals[$j - 1][1]) {
                        throw new RuleException("فترات الطبيب {$name} تتداخل يوم {$dates[$i]}");
                    }
                }
            }
        }
    }

    /**
     * Writes the specialty's days, intervals and hours, and its doctors list.
     * Never drops bookings: a day or hour that would lose capacity below its
     * booked appointments is refused.
     *
     * @throws RuleException
     */
    public function sync(EventSpecialty $eventSpecialty, array $schedule): void
    {
        $byDate = [];
        foreach ($schedule as $entry) {
            foreach ($entry['days'] as $day) {
                $date = date('Y-m-d', strtotime($day['day_date']));
                foreach ($day['intervals'] as $iv) {
                    $start = self::minutes($iv['start_time']);
                    $end = self::minutes($iv['end_time']);
                    $byDate[$date][] = [
                        'id' => $iv['id'] ?? null,
                        'doctor_id' => (int) $entry['doctor_id'],
                        'start_time' => self::time($start),
                        'end_time' => self::time($end),
                        'visit_minutes' => (int) $iv['visit_minutes'],
                        'visits_count' => self::visits($start, $end, (int) $iv['visit_minutes']),
                    ];
                }
            }
        }

        $existingDays = Day::where('event_specialty_id', $eventSpecialty->id)->get()
            ->keyBy(fn ($d) => date('Y-m-d', strtotime($d->day_date)));

        $keptDayIds = [];
        foreach ($byDate as $date => $intervals) {
            $day = $existingDays[$date] ?? Day::create([
                'event_specialty_id' => $eventSpecialty->id,
                'day_date' => $date,
                'number_per_hour' => null,
            ]);
            $keptDayIds[] = $day->id;

            $this->syncIntervals($day, $intervals);
            $this->syncHours($day, self::hourlyCapacity($intervals));
        }

        $removedDays = Day::where('event_specialty_id', $eventSpecialty->id)->whereNotIn('id', $keptDayIds)->get();
        foreach ($removedDays as $day) {
            $booked = Appointment::where('day_id', $day->id)->count();
            if ($booked > 0) {
                throw new RuleException("لا يمكن حذف يوم {$this->date($day)} وفيه {$booked} موعد محجوز. انقل المواعيد أو احذفها أولاً.", 409);
            }
            $day->delete(); // intervals and hours cascade
        }

        $eventSpecialty->doctors()->sync(array_map(fn ($e) => (int) $e['doctor_id'], $schedule));
    }

    private function syncIntervals(Day $day, array $intervals): void
    {
        $existing = DoctorInterval::where('day_id', $day->id)->get()->keyBy('id');
        $keptIds = [];

        foreach ($intervals as $iv) {
            $data = collect($iv)->except('id')->all();
            $current = $iv['id'] ? ($existing[(int) $iv['id']] ?? null) : null;
            if ($current) {
                $current->update($data);
                $keptIds[] = $current->id;
            } else {
                $keptIds[] = DoctorInterval::create($data + ['day_id' => $day->id])->id;
            }
        }

        DoctorInterval::where('day_id', $day->id)->whereNotIn('id', $keptIds)->delete();
    }

    /** @param array<string,int> $capacity 'HH:00:00' => visits */
    private function syncHours(Day $day, array $capacity): void
    {
        $existing = Hour::where('day_id', $day->id)->get()
            ->keyBy(fn ($h) => self::time(self::minutes((string) $h->time)));

        foreach ($existing as $time => $hour) {
            $max = $capacity[$time] ?? 0;
            $booked = Appointment::where('hour_id', $hour->id)->count();
            if ($booked > $max) {
                $at = substr($time, 0, 5);
                throw new RuleException(
                    "الساعة {$at} يوم {$this->date($day)} فيها {$booked} موعد محجوز، والفترات الجديدة تعطيها {$max} فقط. أبقِ سعة كافية أو انقل المواعيد أولاً.",
                    409
                );
            }
            if ($max === 0) {
                $hour->delete();
            } elseif ((int) $hour->max_allowed !== $max) {
                $hour->update(['max_allowed' => $max]);
            }
        }

        foreach ($capacity as $time => $max) {
            if (!isset($existing[$time])) {
                Hour::create(['day_id' => $day->id, 'time' => $time, 'max_allowed' => $max, 'counter' => 0]);
            }
        }
    }

    private function date(Day $day): string
    {
        return date('Y-m-d', strtotime($day->day_date));
    }
}
