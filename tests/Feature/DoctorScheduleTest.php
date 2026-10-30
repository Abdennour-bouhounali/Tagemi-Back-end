<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\User;
use App\Services\DoctorScheduleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Event specialties planned with doctors' intervals; the bookable hours are
 * computed from them. Run against a migrated, empty database:
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class DoctorScheduleTest extends TestCase
{
    use DatabaseTransactions;

    private int $specialtyId;
    private int $otherSpecialtyId;
    private Doctor $drA;
    private Doctor $drB;
    private User $admin;
    private string $date = '2030-03-10';

    protected function setUp(): void
    {
        parent::setUp();

        // Every role: users default to role 2 (empty database in CI)
        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        // Booking code stores user_id = 1 (the super admin in production)
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);
        $this->specialtyId = DB::table('specialties')->insertGetId(['name' => 'عيون']);
        $this->otherSpecialtyId = DB::table('specialties')->insertGetId(['name' => 'أسنان']);
        $this->drA = $this->doctor('أ', $this->specialtyId);
        $this->drB = $this->doctor('ب', $this->specialtyId);

        $this->admin = User::create(['name' => 'a', 'email' => uniqid('a', true) . '@test.local', 'password' => 'password123']);
        $this->admin->forceFill(['role_id' => 1])->save();
        Sanctum::actingAs($this->admin);
    }

    private function doctor(string $name, int $specialtyId): Doctor
    {
        return Doctor::create([
            'first_name' => $name, 'family_name' => 'طبيب', 'phone' => '07' . random_int(10000000, 99999999),
            'wilaya_code' => '16', 'specialty_id' => $specialtyId, 'sector' => 'public', 'is_active' => true,
        ]);
    }

    private function interval(string $start, string $end, int $visit, ?int $id = null): array
    {
        return ['id' => $id, 'start_time' => $start, 'end_time' => $end, 'visit_minutes' => $visit];
    }

    /** Dr A 08:30-10:00 / 20 min, Dr B 09:00-11:00 / 15 min, same day. */
    private function schedule(): array
    {
        return [
            ['doctor_id' => $this->drA->id, 'days' => [['day_date' => $this->date, 'intervals' => [$this->interval('08:30', '10:00', 20)]]]],
            ['doctor_id' => $this->drB->id, 'days' => [['day_date' => $this->date, 'intervals' => [$this->interval('09:00', '11:00', 15)]]]],
        ];
    }

    private function event(array $schedule): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/events', [
            'name' => 'قافلة', 'date' => $this->date,
            'specialties' => [['id' => $this->specialtyId, 'schedule' => $schedule]],
        ]);
    }

    private function hours(int $eventId): array
    {
        return DB::table('hours')
            ->join('days', 'days.id', '=', 'hours.day_id')
            ->join('event_specialties as es', 'es.id', '=', 'days.event_specialty_id')
            ->where('es.event_id', $eventId)
            ->orderBy('hours.time')
            ->pluck('hours.max_allowed', 'hours.time')
            ->map(fn ($v) => (int) $v)->all();
    }

    private function book(int $eventId, string $time): void
    {
        $hour = DB::table('hours')->join('days', 'days.id', '=', 'hours.day_id')
            ->join('event_specialties as es', 'es.id', '=', 'days.event_specialty_id')
            ->where('es.event_id', $eventId)->where('hours.time', $time)
            ->select('hours.id', 'hours.day_id', 'es.id as es_id')->first();

        DB::table('appointments')->insert([
            'event_id' => $eventId, 'user_id' => $this->admin->id, 'event_specialty_id' => $hour->es_id,
            'day_id' => $hour->day_id, 'hour_id' => $hour->id, 'full_name' => uniqid('p'), 'birthday' => '1990-01-01',
            'sex' => '0', 'phone' => '0555000000', 'phone2' => '0555000001', 'patient_id' => random_int(100000, 999999),
            'status' => 'Pending',
        ]);
        DB::table('hours')->where('id', $hour->id)->increment('counter');
    }

    public function test_hourly_capacity_counts_visits_by_their_start_hour(): void
    {
        $capacity = DoctorScheduleService::hourlyCapacity([
            ['start_time' => '08:30', 'end_time' => '10:00', 'visit_minutes' => 20], // 08:30 08:50 | 09:10 09:30
            ['start_time' => '09:00', 'end_time' => '11:00', 'visit_minutes' => 15], // 4 in 09h, 4 in 10h
        ]);

        $this->assertSame(['08:00:00' => 2, '09:00:00' => 6, '10:00:00' => 4], $capacity);
        $this->assertSame(2, DoctorScheduleService::visits(0, 50, 20)); // 10 minutes left over
    }

    public function test_create_event_computes_hours_from_intervals(): void
    {
        $eventId = $this->event($this->schedule())->assertCreated()->json('event.id');

        $this->assertSame(['08:00:00' => 2, '09:00:00' => 6, '10:00:00' => 4], $this->hours($eventId));
        $this->assertSame(2, DB::table('doctor_intervals')->whereIn('doctor_id', [$this->drA->id, $this->drB->id])->count());
        $this->assertNull(DB::table('days')->join('event_specialties as es', 'es.id', '=', 'days.event_specialty_id')->where('es.event_id', $eventId)->value('number_per_hour'));

        $esId = DB::table('event_specialties')->where('event_id', $eventId)->value('id');
        $this->assertEqualsCanonicalizing([$this->drA->id, $this->drB->id], DB::table('event_specialty_doctors')->where('event_specialty_id', $esId)->pluck('doctor_id')->all());

        // The edit form gets the schedule back
        $data = $this->getJson("/api/events/{$eventId}/edit-data")->assertOk()->json('specialties.0');
        $this->assertCount(2, $data['schedule']);
        $this->assertSame('08:30', collect($data['schedule'])->firstWhere('doctor_id', $this->drA->id)['days'][0]['intervals'][0]['start_time']);
    }

    public function test_extra_visits_are_spread_over_the_interval_hours(): void
    {
        // 08:30-10:00 / 20 min = 2 in 08h + 2 in 09h; 3 extra -> 08h, 09h, 08h
        $capacity = DoctorScheduleService::hourlyCapacity([
            ['start_time' => '08:30', 'end_time' => '10:00', 'visit_minutes' => 20, 'extra_visits' => 3],
        ]);
        $this->assertSame(['08:00:00' => 4, '09:00:00' => 3], $capacity);

        $schedule = [['doctor_id' => $this->drA->id, 'days' => [['day_date' => $this->date, 'intervals' => [
            $this->interval('08:30', '10:00', 20) + ['extra_visits' => 5],
        ]]]]];
        $eventId = $this->event($schedule)->assertCreated()->json('event.id');

        $this->assertSame(['08:00:00' => 5, '09:00:00' => 4], $this->hours($eventId));
        $this->assertSame(9, (int) DB::table('doctor_intervals')->where('doctor_id', $this->drA->id)->value('visits_count'));
        $data = $this->getJson("/api/events/{$eventId}/edit-data")->assertOk()->json('specialties.0.schedule.0.days.0.intervals.0');
        $this->assertSame(5, $data['extra_visits']);
    }

    public function test_invalid_schedules_are_refused(): void
    {
        $overlap = [['doctor_id' => $this->drA->id, 'days' => [['day_date' => $this->date, 'intervals' => [
            $this->interval('08:00', '10:00', 20), $this->interval('09:30', '11:00', 20),
        ]]]]];
        $this->event($overlap)->assertStatus(422)->assertJsonFragment(['message' => "فترات الطبيب {$this->drA->full_name} تتداخل يوم {$this->date}"]);

        $backwards = [['doctor_id' => $this->drA->id, 'days' => [['day_date' => $this->date, 'intervals' => [$this->interval('10:00', '09:00', 20)]]]]];
        $this->event($backwards)->assertStatus(422);

        $tooShort = [['doctor_id' => $this->drA->id, 'days' => [['day_date' => $this->date, 'intervals' => [$this->interval('09:00', '09:10', 20)]]]]];
        $this->event($tooShort)->assertStatus(422);

        $other = $this->doctor('ج', $this->otherSpecialtyId);
        $this->event([['doctor_id' => $other->id, 'days' => [['day_date' => $this->date, 'intervals' => [$this->interval('08:00', '09:00', 20)]]]]])->assertStatus(422);

        $this->drB->update(['is_active' => false]);
        $this->event([['doctor_id' => $this->drB->id, 'days' => [['day_date' => $this->date, 'intervals' => [$this->interval('08:00', '09:00', 20)]]]]])->assertStatus(422);

        $this->assertSame(0, DB::table('events')->where('name', 'قافلة')->count());
    }

    public function test_edit_keeps_bookings_safe(): void
    {
        $eventId = $this->event($this->schedule())->assertCreated()->json('event.id');
        $esId = DB::table('event_specialties')->where('event_id', $eventId)->value('id');
        $this->book($eventId, '08:00:00');
        $this->book($eventId, '08:00:00');

        $update = fn (array $schedule) => $this->putJson("/api/events/{$eventId}/full", [
            'name' => 'قافلة', 'date' => $this->date, 'end_date' => '2030-03-11',
            'specialties' => [['id' => $this->specialtyId, 'eventSpecialtyId' => $esId, 'schedule' => $schedule]],
        ]);

        // Dr A starting at 09:00 would leave hour 08 with no room for its 2 bookings
        $moved = $this->schedule();
        $moved[0]['days'][0]['intervals'][0] = $this->interval('09:00', '10:00', 20);
        $update($moved)->assertStatus(409);
        $this->assertSame(2, $this->hours($eventId)['08:00:00']);

        // Shorter visits keep room: 08:30-10:00 every 10 min = 3 in hour 08
        $faster = $this->schedule();
        $faster[0]['days'][0]['intervals'][0] = $this->interval('08:30', '10:00', 10);
        $update($faster)->assertOk();
        $this->assertSame(['08:00:00' => 3, '09:00:00' => 10, '10:00:00' => 4], $this->hours($eventId));
        $this->assertSame(2, DB::table('appointments')->where('event_id', $eventId)->count());

        // Removing the booked day is refused
        $otherDay = [['doctor_id' => $this->drB->id, 'days' => [['day_date' => '2030-03-11', 'intervals' => [$this->interval('09:00', '11:00', 15)]]]]];
        $update($otherDay)->assertStatus(409);
    }

    public function test_busy_dates_list_other_events(): void
    {
        $eventId = $this->event($this->schedule())->assertCreated()->json('event.id');

        $busy = $this->getJson('/api/doctors/busy-dates')->assertOk()->json('busy');
        $this->assertContains($this->drA->id, array_column($busy, 'doctor_id'));

        $this->assertSame([], array_values(array_filter(
            $this->getJson("/api/doctors/busy-dates?exclude_event_id={$eventId}")->json('busy'),
            fn ($row) => $row['event_id'] === $eventId
        )));
    }

    public function test_patients_can_book_the_computed_hours(): void
    {
        $eventId = $this->event($this->schedule())->assertCreated()->json('event.id');
        $slot = DB::table('hours')->join('days', 'days.id', '=', 'hours.day_id')
            ->join('event_specialties as es', 'es.id', '=', 'days.event_specialty_id')
            ->where('es.event_id', $eventId)->where('hours.time', '08:00:00')
            ->select('hours.id as hour_id', 'hours.day_id', 'es.id as es_id')->first();

        $book = fn (string $name) => $this->postJson("/api/appointment/{$eventId}", [
            'name' => $name, 'lastName' => 'بن علي', 'birthday' => '1990-05-05', 'state' => 'Adrar', 'city' => 'Adrar',
            'phone' => '0555000111', 'phone2' => '0555000222', 'sex' => '0',
            'specialties' => [['event_specialty_id' => $slot->es_id, 'day_id' => $slot->day_id, 'hour_id' => $slot->hour_id]],
        ]);

        // Hour 08 holds 2 visits (Dr A at 08:30 and 08:50); the day has no number_per_hour
        $book('أحمد')->assertCreated();
        $book('محمد')->assertCreated();
        $book('سعيد')->assertStatus(409);

        // Doctors share one room: no doctor is assigned at booking
        $this->assertSame(0, DB::table('appointments')->where('event_id', $eventId)->whereNotNull('doctor_id')->count());
    }

    public function test_same_specialty_twice_is_a_validation_error(): void
    {
        $entry = ['id' => $this->specialtyId, 'schedule' => $this->schedule()];
        $this->postJson('/api/events', ['name' => 'مكرر', 'date' => $this->date, 'specialties' => [$entry, $entry]])
            ->assertStatus(422)->assertJsonValidationErrors('specialties.1.id');
    }

    public function test_legacy_hourly_payload_still_works(): void
    {
        $this->postJson('/api/events', [
            'name' => 'قديم', 'date' => $this->date,
            'specialties' => [['id' => $this->specialtyId, 'doctors' => [], 'days' => [
                ['day_date' => $this->date, 'number_per_hour' => 5, 'hours' => [['time' => '08:00:00'], ['time' => '09:00:00']]],
            ]]],
        ])->assertCreated();
    }

    public function test_event_end_date_bounds_the_doctors_days(): void
    {
        $next = date('Y-m-d', strtotime($this->date . ' +1 day'));
        $later = date('Y-m-d', strtotime($this->date . ' +5 days'));
        $day = fn (string $date) => ['day_date' => $date, 'intervals' => [$this->interval('08:00', '09:00', 20)]];
        $post = fn (string $end, string $dayDate) => $this->postJson('/api/events', [
            'name' => 'قافلة', 'date' => $this->date, 'end_date' => $end,
            'specialties' => [['id' => $this->specialtyId, 'schedule' => [
                ['doctor_id' => $this->drA->id, 'days' => [$day($this->date), $day($dayDate)]],
            ]]],
        ]);

        $id = $post($next, $next)->assertCreated()->json('event.id');
        $this->assertSame($next, DB::table('events')->where('id', $id)->value('end_date'));

        $post($next, $later)->assertStatus(422)->assertJsonFragment(['message' => "اليوم {$later} خارج تواريخ الحدث ({$this->date} إلى {$next})"]);
        $this->postJson('/api/events', ['name' => 'x', 'date' => $next, 'end_date' => $this->date, 'specialties' => [['id' => $this->specialtyId, 'schedule' => $this->schedule()]]])
            ->assertStatus(422)->assertJsonValidationErrors('end_date');

        // Without an end date the event lasts one day
        $id = $this->event($this->schedule())->assertCreated()->json('event.id');
        $this->assertSame($this->date, DB::table('events')->where('id', $id)->value('end_date'));
    }
}
