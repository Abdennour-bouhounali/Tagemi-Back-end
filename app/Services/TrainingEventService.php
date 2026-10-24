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

        for ($i = 0; $i < self::APPOINTMENTS; $i++) {
            $s = $i % count($slots);
            [$eventSpecialty, $day, $hours] = $slots[$s];
            $k = $perSpecialty[$s]++;
            $hour = $hours[intdiv($k, self::PER_HOUR)];

            $male = $i % 2 === 0;
            $firstNames = $male ? self::FIRST_NAMES_M : self::FIRST_NAMES_F;
            [$state, $city] = self::STATES[$i % count(self::STATES)];
            $name = $firstNames[$i % count($firstNames)] . ' ' . self::LAST_NAMES[intdiv($i, 2) % count(self::LAST_NAMES)] . ' ' . ($i + 1);

            Appointment::create([
                'user_id' => 1,
                'event_id' => $event->id,
                'event_specialty_id' => $eventSpecialty->id,
                'day_id' => $day->id,
                'hour_id' => $hour->id,
                'doctor_id' => null,
                'full_name' => $name,
                'birthday' => Carbon::parse(self::today())->subYears(18 + ($i * 7) % 60)->subDays($i * 11 % 300)->toDateString(),
                'state' => $state,
                'city' => $city,
                'sex' => $male ? '0' : '1',
                'phone' => sprintf('0500%06d', $i + 1),
                'phone2' => sprintf('0500%06d', $i + 1),
                'diseases' => 'تجريبي',
                'comment' => 'مريض تجريبي',
                'patient_id' => $nextPatientId++,
                'specialty_order' => 1,
                'position' => $k % self::PER_HOUR + 1,
                'orderList' => 0,
                'status' => 'Pending',
            ]);

            $hour->counter++;
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
