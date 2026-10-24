<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Day;
use App\Models\Event;
use App\Models\EventSpecialty;
use App\Models\Hour;
use App\Models\Specialty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The training event: a simulation of a real visit day, created for today with
 * fake pending patients so staff can practice the whole flow.
 *
 * reset() rebuilds everything under the event as if it were new, so nothing
 * done during the training (statuses, queue openings, evaluations, extra
 * bookings, edited specialties) survives. The event row itself stays, so the
 * staff accounts assigned to it keep working.
 */
class TrainingEventService
{
    public const SPECIALTIES = 8;
    public const APPOINTMENTS = 100;
    public const PER_HOUR = 4;
    // Specialties booked per patient, cycled: 8x1, 3x2, 2x3 = 20 appointments per 13 patients
    public const BOOKING_SIZES = [1, 2, 1, 3, 1, 1, 2, 1, 3, 1, 2, 1, 1];
    public const HOURS = ['08:00:00', '09:00:00', '10:00:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00'];

    private const FIRST_NAMES_M = ['محمد', 'أحمد', 'يوسف', 'عمر', 'إبراهيم', 'خالد', 'مصطفى', 'عبد الله', 'ياسين', 'سليمان', 'رشيد', 'كمال', 'نبيل', 'سمير', 'حمزة'];
    private const FIRST_NAMES_F = ['فاطمة', 'خديجة', 'عائشة', 'مريم', 'أمينة', 'سارة', 'نادية', 'سعاد', 'ليلى', 'حنان', 'إيمان', 'زينب', 'سميرة', 'وردة', 'آسيا'];
    private const LAST_NAMES = ['بن عمر', 'بوعلام', 'مرابط', 'حداد', 'بلقاسم', 'شريف', 'زروقي', 'عمراني', 'بوزيد', 'قاسمي', 'مداني', 'حمدي', 'بن يوسف', 'سعدي', 'لعربي', 'بوشامة', 'ختير', 'تومي', 'غربي', 'رحماني'];
    private const STATES = [['الجزائر', 'الجزائر الوسطى'], ['وهران', 'وهران'], ['قسنطينة', 'قسنطينة'], ['البليدة', 'البليدة'], ['سطيف', 'سطيف']];

    /** The visit day's "today" (same clock as the queue). */
    private static function today(): string
    {
        return QueueService::today();
    }

    public static function existing(): ?Event
    {
        return Event::where('is_simulation', true)->first();
    }

    /** Create the training event for today. Only one exists at a time. */
    public function start(): Event
    {
        return DB::transaction(function () {
            $event = Event::create([
                'name' => 'حدث تدريبي (محاكاة)',
                'date' => self::today(),
                'description' => 'حدث للتدريب فقط. المواعيد والمرضى هنا غير حقيقيين ولن تعمل في الواقع.',
                'nbr_of_allowed_specialties' => 3,
                'rules_ids' => [],
                'is_active' => true,
                'is_archived' => false,
                'is_tawat' => true,
                'is_simulation' => true,
            ]);

            $this->build($event);

            return $event;
        });
    }

    /** Back to a brand-new training event, dated today. */
    public function reset(Event $event): void
    {
        DB::transaction(function () use ($event) {
            $this->wipe($event);

            $event->update([
                'date' => self::today(),
                'is_active' => true,
                'is_archived' => false,
            ]);

            $this->build($event);
        });
    }

    /** Hard delete: the event and everything under it. */
    public function delete(Event $event): void
    {
        DB::transaction(function () use ($event) {
            $this->wipe($event);
            $event->delete();
        });
    }

    /** Removes all children of the event (specialties cascade to days, hours, bookings). */
    private function wipe(Event $event): void
    {
        DB::table('appointments')->where('event_id', $event->id)->delete();
        DB::table('appointments_archive')->where('event_id', $event->id)->delete();
        DB::table('evaluations')->where('event_id', $event->id)->delete();
        DB::table('event_reports')->where('event_id', $event->id)->delete();
        DB::table('event_doctors')->where('event_id', $event->id)->delete();
        EventSpecialty::where('event_id', $event->id)->delete();
    }

    private function build(Event $event): void
    {
        $today = self::today();
        $specialties = Specialty::orderBy('id')->limit(self::SPECIALTIES)->get();

        $slots = []; // per specialty: list of [hour model, day id, event specialty]
        foreach ($specialties as $specialty) {
            $eventSpecialty = EventSpecialty::create([
                'event_id' => $event->id,
                'specialty_id' => $specialty->id,
                'is_saturated' => false,
                'has_dilatation' => str_contains($specialty->name, 'تصحيح النظر'),
                'max_number' => 100,
                'start_time' => self::HOURS[0],
                'addition_capacity' => 0,
                'flag' => 'Open',
                'is_active' => true,
            ]);

            $day = Day::create([
                'event_specialty_id' => $eventSpecialty->id,
                'day_date' => $today,
                'number_per_hour' => self::PER_HOUR,
            ]);

            $hours = [];
            foreach (self::HOURS as $time) {
                $hours[] = Hour::create([
                    'day_id' => $day->id,
                    'time' => $time,
                    'max_allowed' => self::PER_HOUR,
                    'counter' => 0,
                ]);
            }

            $slots[] = [$eventSpecialty, $day, $hours];
        }

        if (!$slots) {
            return;
        }

        $nextPatientId = (int) Appointment::max('patient_id') + 1;
        $perSpecialty = array_fill(0, count($slots), 0);
        $count = count($slots);

        // Patients book 1, 2 or 3 specialties (65 patients, 100 appointments)
        $created = 0;
        for ($p = 0; $created < self::APPOINTMENTS; $p++) {
            $wanted = min(self::BOOKING_SIZES[$p % count(self::BOOKING_SIZES)], self::APPOINTMENTS - $created, $count);

            $male = $p % 2 === 0;
            $firstNames = $male ? self::FIRST_NAMES_M : self::FIRST_NAMES_F;
            [$state, $city] = self::STATES[$p % count(self::STATES)];
            $name = $firstNames[$p % count($firstNames)] . ' ' . self::LAST_NAMES[intdiv($p, 2) % count(self::LAST_NAMES)] . ' ' . ($p + 1);
            $birthday = Carbon::parse(self::today())->subYears(18 + ($p * 7) % 60)->subDays($p * 11 % 300)->toDateString();
            $phone = sprintf('0500%06d', $p + 1);
            $patientId = $nextPatientId++;

            for ($j = 0; $j < $wanted; $j++) {
                $s = ($p + $j * 3) % $count; // distinct specialties for one patient
                [$eventSpecialty, $day, $hours] = $slots[$s];
                $k = $perSpecialty[$s]++;
                $hour = $hours[intdiv($k, self::PER_HOUR)];

                Appointment::create([
                    'user_id' => 1,
                    'event_id' => $event->id,
                    'event_specialty_id' => $eventSpecialty->id,
                    'day_id' => $day->id,
                    'hour_id' => $hour->id,
                    'doctor_id' => null,
                    'full_name' => $name,
                    'birthday' => $birthday,
                    'state' => $state,
                    'city' => $city,
                    'sex' => $male ? '0' : '1',
                    'phone' => $phone,
                    'phone2' => $phone,
                    'diseases' => 'تجريبي',
                    'comment' => 'مريض تجريبي',
                    'patient_id' => $patientId,
                    'specialty_order' => $j === 0 ? 1 : 0,
                    'position' => $k % self::PER_HOUR + 1,
                    'orderList' => 0,
                    'status' => 'Pending',
                ]);

                $hour->counter++;
                $created++;
            }
        }

        foreach ($slots as [, , $hours]) {
            foreach ($hours as $hour) {
                if ($hour->isDirty('counter')) {
                    $hour->save();
                }
            }
        }
    }
}
