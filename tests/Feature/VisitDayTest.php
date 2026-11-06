<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Visit-day rules: check-in by day, one queue at a time, priority, push down,
 * transfers, referrals, duplicates, public "my turn" page.
 *   DB_DATABASE=tagemi_security_test php artisan test --filter=VisitDayTest
 */
class VisitDayTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;
    /** event_specialty id => ['today' => [dayId, hourId], 'tomorrow' => [dayId, hourId]] */
    private array $es = [];
    private int $nextPatient = 7000;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager', 9 => 'Organizer'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }

        // Booking code stores user_id = 1 (the super admin in production)
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);

        $this->eventId = DB::table('events')->insertGetId([
            'name' => 'Visit', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 3,
        ]);

        foreach (['Eyes', 'Heart', 'Teeth', 'Skin'] as $name) {
            $specialtyId = DB::table('specialties')->insertGetId(['name' => $name . uniqid()]);
            // Open today (doctor present); tests about opening close them first
            $esId = DB::table('event_specialties')->insertGetId(['event_id' => $this->eventId, 'specialty_id' => $specialtyId, 'opened_on' => QueueService::today()]);
            foreach (['today' => 0, 'tomorrow' => 1] as $key => $offset) {
                $dayId = DB::table('days')->insertGetId([
                    'event_specialty_id' => $esId,
                    'day_date' => Carbon::now(QueueService::TIMEZONE)->addDays($offset)->toDateString(),
                ]);
                $hourId = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => '08:00:00', 'max_allowed' => 50]);
                $this->es[$esId][$key] = [$dayId, $hourId];
            }
        }

        Sanctum::actingAs($this->staff(User::ROLE_SUPER_ADMIN));
    }

    private function staff(int $role): User
    {
        $user = User::create(['name' => 'staff', 'email' => uniqid('s', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role, 'event_id' => $this->eventId])->save();

        return $user;
    }

    private function esIds(): array
    {
        return array_keys($this->es);
    }

    /** @param array $bookings [[esId, 'today'|'tomorrow'], ...] first = primary */
    private function patient(array $bookings, string $name = null, string $phone = '0555123456'): array
    {
        $patientId = ++$this->nextPatient;
        $ids = [];
        foreach ($bookings as $i => [$esId, $when]) {
            [$dayId, $hourId] = $this->es[$esId][$when];
            $ids[] = Appointment::create([
                'user_id' => 1, 'event_id' => $this->eventId, 'event_specialty_id' => $esId,
                'day_id' => $dayId, 'hour_id' => $hourId, 'full_name' => $name ?? "Patient {$patientId}",
                'birthday' => '1980-01-01', 'sex' => '0', 'phone' => $phone, 'phone2' => '0666000000',
                'patient_id' => $patientId, 'specialty_order' => $i === 0 ? 1 : 0, 'status' => 'Pending',
            ])->id;
        }

        return ['patient_id' => $patientId, 'ids' => $ids];
    }

    private function statusOf(int $appointmentId): string
    {
        return Appointment::find($appointmentId)->status;
    }

    private function checkIn(int $patientId)
    {
        return $this->postJson("/api/events/{$this->eventId}/check-in", ['patient_id' => $patientId]);
    }

    private function close(int ...$esIds): void
    {
        DB::table('event_specialties')->whereIn('id', $esIds)->update(['opened_on' => null]);
    }

    /** Open/close a queue; opening needs a present doctor, so one is there. */
    private function toggle(int $esId)
    {
        $this->markArrived($this->doctorFor($esId, 'T'), $esId);

        return $this->patchJson("/api/event-specialties/{$esId}/toggle-open")->assertOk();
    }

    /** Arrival recorded directly (no queue opening), unless already recorded. */
    private function markArrived(Doctor $doctor, int $esId): void
    {
        $dayId = $this->es[$esId]['today'][0];
        if (!DB::table('doctor_attendances')->where('day_id', $dayId)->where('doctor_id', $doctor->id)->exists()) {
            DB::table('doctor_attendances')->insert(['day_id' => $dayId, 'doctor_id' => $doctor->id, 'arrived_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** دخل with a doctor of the specialty (scheduled today), then إجتاز. */
    private function pass(int $appointmentId)
    {
        $appointment = Appointment::find($appointmentId);
        $doctor = $this->doctorFor($appointment->event_specialty_id);
        $this->markArrived($doctor, $appointment->event_specialty_id);
        $this->postJson('/api/waitinglist/enter', ['ids' => [$appointmentId], 'doctor_id' => $doctor->id])->assertOk();

        return $this->postJson("/api/waitinglist/Complete/{$appointmentId}");
    }

    /** A doctor scheduled today in the specialty (08:00-23:59, 20-minute visits). */
    private function doctorFor(int $esId, string $name = 'A'): Doctor
    {
        $specialtyId = DB::table('event_specialties')->where('id', $esId)->value('specialty_id');
        $doctor = Doctor::firstOrCreate(['first_name' => $name . $esId, 'family_name' => 'Dr', 'specialty_id' => $specialtyId], [
            'phone' => '07' . random_int(10000000, 99999999), 'wilaya_code' => '16', 'sector' => 'public', 'is_active' => true, 'sex' => 'male',
        ]);
        DB::table('doctor_intervals')->updateOrInsert(
            ['day_id' => $this->es[$esId]['today'][0], 'doctor_id' => $doctor->id],
            ['start_time' => '00:00:00', 'end_time' => '23:59:00', 'visit_minutes' => 20, 'visits_count' => 70, 'extra_visits' => 0],
        );

        return $doctor;
    }

    /** Patient ids of a specialty queue, in order. */
    private function queue(int $esId): array
    {
        return app(QueueService::class)->queue($esId)->pluck('patient_id')->all();
    }

    // ------------------------------------------------------------ check-in

    public function test_check_in_only_activates_todays_appointments(): void
    {
        [$a, $b, $c] = $this->esIds();
        $p = $this->patient([[$a, 'today'], [$b, 'tomorrow'], [$c, 'today']]);

        $this->checkIn($p['patient_id'])->assertOk();

        $this->assertSame('Present', $this->statusOf($p['ids'][0]));
        $this->assertSame('Pending', $this->statusOf($p['ids'][1])); // tomorrow: untouched
        $this->assertSame('Waiting', $this->statusOf($p['ids'][2]));
    }

    public function test_check_in_refuses_twice_and_on_wrong_day(): void
    {
        [$a] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id'])->assertOk();
        $this->checkIn($p['patient_id'])->assertStatus(409);

        $later = $this->patient([[$a, 'tomorrow']]);
        $res = $this->checkIn($later['patient_id'])->assertStatus(422);
        $this->assertStringContainsString(Carbon::now(QueueService::TIMEZONE)->addDay()->toDateString(), $res->json('message'));

        $this->checkIn(999999)->assertNotFound();
    }

    public function test_reception_can_check_in_by_typed_number(): void
    {
        [$a] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));

        $this->checkIn($p['patient_id'])->assertOk()->assertJsonPath('position', 1);
    }

    public function test_officer_queue_shows_today_only(): void
    {
        [$a] = $this->esIds();
        $today = $this->patient([[$a, 'today']]);
        $tomorrow = $this->patient([[$a, 'tomorrow']]);
        $this->checkIn($today['patient_id']);
        // Stale row from another day must not appear in today's queue
        Appointment::whereKey($tomorrow['ids'][0])->update(['status' => 'Present', 'orderList' => 1]);

        $specialtyId = DB::table('event_specialties')->where('id', $a)->value('specialty_id');
        $ids = collect($this->getJson("/api/waitinglist/GetWaitingListBySpeciality/{$this->eventId}/{$specialtyId}")->assertOk()->json('appointments'))->pluck('patient_id')->all();

        $this->assertSame([$today['patient_id']], $ids);
    }

    // ------------------------------------------- open specialties, least busy

    public function test_check_in_goes_to_least_busy_open_specialty(): void
    {
        [$a, $b] = $this->esIds();
        // Equal queues: the primary specialty wins
        $tie = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($tie['patient_id']);
        $this->assertSame('Present', $this->statusOf($tie['ids'][0]));

        // a now has 1, b has 0: the next patient goes to b although a is primary
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($p['patient_id'])->assertOk()->assertJsonPath('position', 1);
        $this->assertSame('Waiting', $this->statusOf($p['ids'][0]));
        $this->assertSame('Present', $this->statusOf($p['ids'][1]));
    }

    public function test_check_in_skips_closed_specialties_and_waits_when_none_open(): void
    {
        [$a, $b, $c] = $this->esIds();
        $this->close($a);
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($p['patient_id'])->assertOk();
        $this->assertSame([$p['patient_id']], $this->queue($b));
        $this->assertSame([], $this->queue($a));

        $this->close($c);
        $q = $this->patient([[$c, 'today']]);
        $res = $this->checkIn($q['patient_id'])->assertOk()->assertJsonPath('appointment', null);
        $this->assertStringContainsString('لا يوجد تخصص مفتوح', $res->json('message'));
        $this->assertSame('Waiting', $this->statusOf($q['ids'][0]));
        $this->assertNotNull(Appointment::find($q['ids'][0])->checked_in_at);
        // Checked in: a second check-in is refused
        $this->checkIn($q['patient_id'])->assertStatus(409);
    }

    public function test_opening_sends_waiting_patients_in_arrival_order(): void
    {
        [$a] = $this->esIds();
        $this->close($a);
        $first = $this->patient([[$a, 'today']]);
        $second = $this->patient([[$a, 'today']]);
        $special = $this->patient([[$a, 'today']]);
        $this->checkIn($first['patient_id']);
        Appointment::whereKey($first['ids'][0])->update(['checked_in_at' => now()->subMinutes(10)]);
        $this->checkIn($second['patient_id']);
        $this->postJson("/api/waitinglist/Special/{$special['ids'][0]}")->assertOk();
        $this->assertSame('Waiting', $this->statusOf($special['ids'][0]));
        $this->assertSame([], $this->queue($a));

        $this->toggle($a)->assertJsonPath('is_open', true)->assertJsonPath('sent', 3);

        $this->assertSame([$special['patient_id'], $first['patient_id'], $second['patient_id']], $this->queue($a));
    }

    public function test_opening_rebalances_from_the_back_of_longer_queues(): void
    {
        [$a, $b] = $this->esIds();
        $this->close($b);
        $p = [];
        foreach (range(1, 10) as $i) {
            $p[$i] = $this->patient([[$a, 'today'], [$b, 'today']]);
            $this->checkIn($p[$i]['patient_id']);
        }
        // A priority patient at the back is never moved
        Appointment::whereKey($p[10]['ids'][0])->update(['is_priority' => true]);
        $n = fn (...$is) => array_map(fn ($i) => $p[$i]['patient_id'], $is);

        $this->toggle($b)->assertJsonPath('sent', 0)->assertJsonPath('moved', 4);

        // First 5 protected; 9, 8, 7, 6 move while it brings them closer to the
        // front, then keep their arrival order in the new queue
        $this->assertSame($n(6, 7, 8, 9), $this->queue($b));
        $this->assertSame($n(1, 2, 3, 4, 5, 10), $this->queue($a));
        $this->assertSame('Waiting', $this->statusOf($p[9]['ids'][0]));
    }

    public function test_passed_goes_to_least_busy_open_specialty(): void
    {
        [$a, $b, $c] = $this->esIds();
        DB::table('events')->where('id', $this->eventId)->update(['nbr_of_allowed_specialties' => 4]);
        $this->checkIn($this->patient([[$b, 'today']])['patient_id']);
        $p = $this->patient([[$a, 'today'], [$b, 'today'], [$c, 'today'], [$this->esIds()[3], 'today']]);
        $this->checkIn($p['patient_id']); // a
        $this->close($this->esIds()[3]);

        $this->pass($p['ids'][0])->assertOk();

        // b has 1 waiting, c none, d closed: c
        $this->assertSame('Present', $this->statusOf($p['ids'][2]));
        $this->assertSame('Waiting', $this->statusOf($p['ids'][1]));
        $this->assertSame('Waiting', $this->statusOf($p['ids'][3]));
    }

    public function test_closing_keeps_the_queue_but_stops_new_patients(): void
    {
        [$a, $b] = $this->esIds();
        $before = $this->patient([[$a, 'today']]);
        $this->checkIn($before['patient_id']);

        $this->toggle($a)->assertJsonPath('is_open', false);
        $after = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($after['patient_id']);

        $this->assertSame([$before['patient_id']], $this->queue($a));
        $this->assertSame([$after['patient_id']], $this->queue($b));
        $this->pass($before['ids'][0])->assertOk();

        // Hall screen data: the open state is public
        $open = collect($this->getJson("/api/public/events/{$this->eventId}/specialties")->assertOk()->json('event_specialties'))
            ->pluck('queue_open', 'id');
        $this->assertFalse($open[$a]);
        $this->assertTrue($open[$b]);
    }

    public function test_transfer_to_closed_specialty_waits(): void
    {
        [$a, $b] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id']);
        $this->close($b);

        $this->postJson("/api/appointments/{$p['ids'][0]}/transfer-specialty", ['new_event_specialty_id' => $b, 'use_closest_slot' => true])->assertOk();

        $this->assertSame('Waiting', $this->statusOf($p['ids'][0]));
        $this->assertSame([], $this->queue($b));
    }

    // ------------------------------------------------- one queue at a time

    public function test_passed_moves_patient_to_end_of_next_queue(): void
    {
        [$a, $b] = $this->esIds();
        $other = $this->patient([[$b, 'today']]);
        $this->checkIn($other['patient_id']);
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($p['patient_id']);

        $this->pass($p['ids'][0])->assertOk();

        $this->assertSame('Completed', $this->statusOf($p['ids'][0]));
        $this->assertSame([$other['patient_id'], $p['patient_id']], $this->queue($b));

        $this->pass($p['ids'][1])->assertOk()->assertJsonMissingPath('next_appointment');
    }

    // --------------------------------------------------------- special case

    public function test_special_case_before_check_in_checks_in_with_priority(): void
    {
        [$a, $b] = $this->esIds();
        $normal = $this->patient([[$b, 'today']]);
        $this->checkIn($normal['patient_id']);
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);

        // Special on the 2nd specialty: it becomes the active one, at the head
        $this->postJson("/api/waitinglist/Special/{$p['ids'][1]}")->assertOk();
        $this->assertSame('Present', $this->statusOf($p['ids'][1]));
        $this->assertSame('Waiting', $this->statusOf($p['ids'][0]));
        $this->assertSame([$p['patient_id'], $normal['patient_id']], $this->queue($b));

        // After Passed, the other specialty is not lost and keeps priority
        $other = $this->patient([[$a, 'today']]);
        $this->checkIn($other['patient_id']);
        $this->pass($p['ids'][1])->assertOk();
        $this->assertSame([$p['patient_id'], $other['patient_id']], $this->queue($a));
    }

    public function test_special_case_on_waiting_specialty_keeps_one_active_queue(): void
    {
        [$a, $b] = $this->esIds();
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($p['patient_id']); // a Present, b Waiting

        $this->postJson("/api/waitinglist/Special/{$p['ids'][1]}")->assertOk();

        $this->assertSame('Waiting', $this->statusOf($p['ids'][0]));
        $this->assertSame('Present', $this->statusOf($p['ids'][1]));
        $this->assertSame([], $this->queue($a));
    }

    public function test_priority_patients_are_served_in_arrival_order(): void
    {
        [$a] = $this->esIds();
        $normal = $this->patient([[$a, 'today']]);
        $this->checkIn($normal['patient_id']);
        $first = $this->patient([[$a, 'today']]);
        $second = $this->patient([[$a, 'today']]);

        $this->postJson("/api/waitinglist/Special/{$first['ids'][0]}")->assertOk();
        $this->postJson("/api/waitinglist/Special/{$second['ids'][0]}")->assertOk();

        $this->assertSame([$first['patient_id'], $second['patient_id'], $normal['patient_id']], $this->queue($a));
    }

    // ------------------------------------------- manual changes (logged)

    public function test_move_to_front_puts_patient_third_without_special_mark(): void
    {
        [$a, $b] = $this->esIds();
        $first = $this->patient([[$a, 'today']]);
        $this->checkIn($first['patient_id']);
        $last = $this->patient([[$a, 'today']]);
        $this->checkIn($last['patient_id']);
        $special = $this->patient([[$a, 'today']]);
        $this->postJson("/api/waitinglist/Special/{$special['ids'][0]}")->assertOk();
        // Not checked in yet, and booked in two specialties
        $p = $this->patient([[$b, 'today'], [$a, 'today']]);

        Sanctum::actingAs($admin = $this->staff(User::ROLE_SPECIAL_ADMIN));
        $this->postJson("/api/waitinglist/move-to-front/{$p['ids'][1]}")->assertOk();

        $this->assertSame([$special['patient_id'], $first['patient_id'], $p['patient_id'], $last['patient_id']], $this->queue($a));
        $moved = Appointment::find($p['ids'][1]);
        $this->assertFalse($moved->is_special);
        $this->assertFalse($moved->is_priority);
        $this->assertNotNull($moved->checked_in_at);
        $this->assertSame('Waiting', $this->statusOf($p['ids'][0]));
        $this->assertDatabaseHas('appointment_logs', ['appointment_id' => $moved->id, 'action' => 'moved_to_front', 'user_id' => $admin->id]);

        // Reception cannot do it
        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));
        $this->postJson("/api/waitinglist/move-to-front/{$first['ids'][0]}")->assertForbidden();
    }

    public function test_super_admin_reschedules_and_counters_follow(): void
    {
        [$a] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        [$todayDay, $todayHour] = $this->es[$a]['today'];
        [$tomorrowDay, $tomorrowHour] = $this->es[$a]['tomorrow'];
        DB::table('hours')->where('id', $todayHour)->update(['counter' => 1]);

        $this->patchJson("/api/appointments/{$p['ids'][0]}/reschedule", ['day_id' => $tomorrowDay, 'hour_id' => $tomorrowHour])->assertOk();

        $moved = Appointment::find($p['ids'][0]);
        $this->assertSame($tomorrowHour, (int) $moved->hour_id);
        $this->assertSame(0, DB::table('hours')->where('id', $todayHour)->value('counter'));
        $this->assertSame(1, DB::table('hours')->where('id', $tomorrowHour)->value('counter'));
        $this->assertDatabaseHas('appointment_logs', ['appointment_id' => $moved->id, 'action' => 'rescheduled', 'user_id' => auth()->id()]);

        // An hour of another specialty is refused; a queued patient cannot be moved
        [$b] = array_slice($this->esIds(), 1);
        $this->patchJson("/api/appointments/{$p['ids'][0]}/reschedule", ['day_id' => $this->es[$b]['today'][0], 'hour_id' => $this->es[$b]['today'][1]])->assertStatus(422);
        $q = $this->patient([[$a, 'today']]);
        $this->checkIn($q['patient_id']);
        $this->patchJson("/api/appointments/{$q['ids'][0]}/reschedule", ['day_id' => $tomorrowDay, 'hour_id' => $tomorrowHour])->assertStatus(422);

        // Only the super admin
        Sanctum::actingAs($this->staff(User::ROLE_SPECIAL_ADMIN));
        $this->patchJson("/api/appointments/{$p['ids'][0]}/reschedule", ['day_id' => $todayDay, 'hour_id' => $todayHour])->assertForbidden();
    }

    public function test_add_specialty_up_to_event_limit_joins_today_on_hold(): void
    {
        [$a, $b, $c, $d] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id']);

        $res = $this->postJson("/api/appointments/{$p['ids'][0]}/add-specialty", [
            'event_specialty_id' => $b, 'day_id' => $this->es[$b]['today'][0], 'hour_id' => $this->es[$b]['today'][1],
        ])->assertCreated();
        $added = Appointment::find($res->json('appointment.id'));
        $this->assertSame($p['patient_id'], (int) $added->patient_id);
        $this->assertSame('Waiting', $added->status); // already active in specialty a
        $this->assertDatabaseHas('appointment_logs', ['appointment_id' => $added->id, 'action' => 'specialty_added']);

        // Same specialty twice refused; limit (3) reached after a third one
        $this->postJson("/api/appointments/{$p['ids'][0]}/add-specialty", [
            'event_specialty_id' => $b, 'day_id' => $this->es[$b]['tomorrow'][0], 'hour_id' => $this->es[$b]['tomorrow'][1],
        ])->assertStatus(422);
        $this->postJson("/api/appointments/{$p['ids'][0]}/add-specialty", [
            'event_specialty_id' => $c, 'day_id' => $this->es[$c]['tomorrow'][0], 'hour_id' => $this->es[$c]['tomorrow'][1],
        ])->assertCreated();
        // Past the limit: the super admin may, the appointment is marked beyond_limit
        $extra = $this->postJson("/api/appointments/{$p['ids'][0]}/add-specialty", [
            'event_specialty_id' => $d, 'day_id' => $this->es[$d]['tomorrow'][0], 'hour_id' => $this->es[$d]['tomorrow'][1],
        ])->assertCreated();
        $this->assertTrue(Appointment::find($extra->json('appointment.id'))->beyond_limit);
    }

    public function test_delete_closes_queue_gap_promotes_next_and_is_logged(): void
    {
        [$a, $b] = $this->esIds();
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($p['patient_id']); // a Present, b Waiting
        $other = $this->patient([[$a, 'today']]);
        $this->checkIn($other['patient_id']);

        $this->deleteJson("/api/appointments/{$p['ids'][0]}")->assertOk();

        $this->assertSame([$other['patient_id']], $this->queue($a));
        $this->assertSame(1, (int) Appointment::find($other['ids'][0])->orderList);
        $this->assertSame('Present', $this->statusOf($p['ids'][1]));
        $this->assertDatabaseHas('appointment_logs', ['appointment_id' => $p['ids'][0], 'action' => 'deleted', 'user_id' => auth()->id()]);

        $log = $this->getJson("/api/events/{$this->eventId}/patients/{$p['patient_id']}/log")->assertOk();
        $this->assertSame('deleted', $log->json('logs.0.action'));
        $this->assertSame('staff', $log->json('logs.0.user'));
    }

    // ----------------------------------------------------------- push down

    public function test_push_down_moves_back_five_places_and_supports_bulk(): void
    {
        [$a] = $this->esIds();
        $p = [];
        foreach (range(1, 8) as $i) {
            $p[$i] = $this->patient([[$a, 'today']]);
            $this->checkIn($p[$i]['patient_id']);
        }
        $n = fn (...$is) => array_map(fn ($i) => $p[$i]['patient_id'], $is);

        $this->postJson("/api/waitinglist/Commingsoon/{$p[1]['ids'][0]}")->assertOk();
        $this->assertSame($n(2, 3, 4, 5, 6, 1, 7, 8), $this->queue($a));
        $this->assertSame('Commingsoon', $this->statusOf($p[1]['ids'][0]));

        // Several at once: each moves back 5 places, everyone else keeps their order
        $this->postJson('/api/waitinglist/push-down', ['appointment_ids' => [$p[2]['ids'][0], $p[3]['ids'][0]]])->assertOk();
        $this->assertSame($n(4, 5, 6, 1, 2, 7, 3, 8), $this->queue($a));
    }

    public function test_patient_manager_can_only_push_down(): void
    {
        [$a, $b] = $this->esIds();
        $p = [];
        foreach (range(1, 3) as $i) {
            $p[$i] = $this->patient([[$a, 'today']]);
            $this->checkIn($p[$i]['patient_id']);
        }
        $first = $p[1]['ids'][0];

        Sanctum::actingAs($this->staff(User::ROLE_PATIENT_MANAGER));
        $specialtyId = DB::table('event_specialties')->where('id', $a)->value('specialty_id');
        $this->getJson("/api/events/{$this->eventId}/stations")->assertOk();
        $this->getJson("/api/waitinglist/GetWaitingListBySpeciality/{$this->eventId}/{$specialtyId}")->assertOk();

        $this->postJson("/api/waitinglist/Complete/{$first}")->assertForbidden();
        $this->postJson("/api/waitinglist/Dilatation/{$first}")->assertForbidden();
        $this->postJson("/api/appointments/{$first}/refer", ['event_specialty_id' => $b])->assertForbidden();
        $this->postJson('/api/appointment/addComment', ['appointment_id' => $first, 'comment' => 'x'])->assertForbidden();
        $this->assertSame('Present', $this->statusOf($first));

        $this->postJson("/api/waitinglist/Commingsoon/{$first}", ['places' => 1])->assertOk();
        $this->assertSame('Commingsoon', $this->statusOf($first));
        $this->postJson('/api/waitinglist/push-down', ['appointment_ids' => [$p[2]['ids'][0]]])->assertOk();
    }

    public function test_push_down_never_lands_inside_priority_block(): void
    {
        [$a] = $this->esIds();
        $p = [];
        foreach (range(1, 5) as $i) {
            $p[$i] = $this->patient([[$a, 'today']]);
            $this->postJson("/api/waitinglist/Special/{$p[$i]['ids'][0]}")->assertOk(); // 5 priority patients
        }

        $this->postJson("/api/waitinglist/Commingsoon/{$p[1]['ids'][0]}")->assertOk();

        $queue = app(QueueService::class)->queue($a);
        $this->assertSame($p[1]['patient_id'], $queue->last()->patient_id);
        $this->assertFalse((bool) $queue->last()->is_priority);
    }

    // ---------------------------------------------------------- dilatation

    public function test_dilatation_room_flow(): void
    {
        [$a, $b] = $this->esIds();
        DB::table('event_specialties')->where('id', $a)->update(['has_dilatation' => true]); // تصحيح النظر
        $first = $this->patient([[$a, 'today']]);
        $second = $this->patient([[$a, 'today']]);
        $normal = $this->patient([[$a, 'today']]);
        foreach ([$first, $second, $normal] as $p) {
            $this->checkIn($p['patient_id']);
        }
        $special = $this->patient([[$a, 'today']]);

        // The officer sends two patients; they leave the queue
        $this->postJson("/api/waitinglist/Dilatation/{$first['ids'][0]}")->assertOk();
        $this->postJson("/api/waitinglist/Dilatation/{$second['ids'][0]}")->assertOk();
        $this->assertSame([$normal['patient_id']], $this->queue($a));
        $this->assertSame('DilatationSent', $this->statusOf($first['ids'][0]));

        // Only specialties with a dilatation room can send
        $other = $this->patient([[$b, 'today']]);
        $this->checkIn($other['patient_id']);
        $this->postJson("/api/waitinglist/Dilatation/{$other['ids'][0]}")->assertStatus(422);

        // The dilatation officer receives them (timer starts)
        $room = $this->getJson("/api/event-specialties/{$a}/dilatation-room")->assertOk();
        $this->assertSame([$first['patient_id'], $second['patient_id']], collect($room->json('coming'))->pluck('patient_id')->all());
        $this->postJson("/api/waitinglist/DilatationArrived/{$first['ids'][0]}")->assertOk();
        $this->postJson("/api/waitinglist/DilatationArrived/{$second['ids'][0]}")->assertOk();
        $this->assertNotNull(Appointment::find($first['ids'][0])->dilatation_started_at);
        $this->assertCount(2, $this->getJson("/api/event-specialties/{$a}/dilatation-room")->json('in_room'));

        // A special case arrives meanwhile, then both return: priority in arrival order
        $this->postJson("/api/waitinglist/Special/{$special['ids'][0]}")->assertOk();
        $this->postJson("/api/waitinglist/FinishedDilatation/{$first['ids'][0]}")->assertOk();
        $this->postJson("/api/waitinglist/FinishedDilatation/{$second['ids'][0]}")->assertOk();

        $this->assertSame(
            [$special['patient_id'], $first['patient_id'], $second['patient_id'], $normal['patient_id']],
            $this->queue($a)
        );

        // Counted as dilatation in statistics while away
        $this->assertSame(0, $this->getJson("/api/appointments/event-statistics/{$this->eventId}")->json('statistics.dilatation'));
    }

    // --------------------------------------------------- transfer / referral

    public function test_transfer_places_patient_third_in_new_queue(): void
    {
        [$a, $b] = $this->esIds();
        $waiting = [];
        foreach (range(1, 4) as $i) {
            $waiting[$i] = $this->patient([[$b, 'today']]);
            $this->checkIn($waiting[$i]['patient_id']);
        }
        $p = $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id']);

        $this->postJson("/api/appointments/{$p['ids'][0]}/transfer-specialty", ['new_event_specialty_id' => $b, 'use_closest_slot' => true])
            ->assertOk()->assertJsonPath('position', 3);
        $this->assertSame($p['patient_id'], $this->queue($b)[2]);
    }

    public function test_send_to_unbooked_open_specialty(): void
    {
        [$a, $b, $c] = $this->esIds();
        foreach (range(1, 3) as $i) {
            $this->checkIn($this->patient([[$c, 'today']])['patient_id']);
        }
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($p['patient_id']);

        $options = collect($this->getJson("/api/appointments/{$p['ids'][0]}/referral-options")->assertOk()->json('specialties'))->pluck('event_specialty_id');
        $this->assertFalse($options->contains($b)); // already booked
        $this->assertTrue($options->contains($c));

        $this->postJson("/api/appointments/{$p['ids'][0]}/refer", ['event_specialty_id' => $c])->assertOk()->assertJsonPath('position', 3);
        $this->assertSame('Waiting', $this->statusOf($p['ids'][0]));
        // Places stay 1..N in every queue after someone leaves or joins
        $this->assertSame([1, 2, 3, 4], app(QueueService::class)->queue($c)->pluck('orderList')->all());

        // A closed specialty is not offered and cannot be used
        $other = $this->patient([[$a, 'today']]);
        $this->checkIn($other['patient_id']);
        $d = $this->esIds()[3];
        $this->patchJson("/api/event-specialties/{$d}/toggle-open")->assertOk()->assertJsonPath('is_open', false);
        $this->postJson("/api/appointments/{$other['ids'][0]}/refer", ['event_specialty_id' => $d])->assertStatus(409);
    }

    // ------------------------------------------------- booking safeguards

    private function book(string $name, array $esIds)
    {
        return $this->postJson("/api/appointment/{$this->eventId}", [
            'name' => $name, 'lastName' => 'بن علي', 'birthday' => '1990-05-05', 'state' => 'Adrar', 'city' => 'Adrar',
            'phone' => '0555000111', 'phone2' => '0555000222', 'sex' => '0', 'data_consent' => '1',
            'specialties' => array_map(fn ($es) => ['event_specialty_id' => $es, 'day_id' => $this->es[$es]['today'][0], 'hour_id' => $this->es[$es]['today'][1]], $esIds),
        ]);
    }

    public function test_specialty_limit_comes_from_the_event(): void
    {
        [$a, $b, $c, $d] = $this->esIds();
        $this->book('أحمد', [$a, $b, $c])->assertCreated();
        $this->book('محمد', [$a, $b, $c, $d])->assertStatus(422);
    }

    public function test_same_person_cannot_book_twice_even_with_spelling_variants(): void
    {
        [$a, $b] = $this->esIds();
        $this->book('أحمد', [$a])->assertCreated();
        $this->book('  احمد ', [$b])->assertStatus(409);

        // The database itself refuses a second row for the same person and specialty
        $first = Appointment::where('event_id', $this->eventId)->latest('id')->first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        Appointment::create(array_merge($first->only(['user_id', 'event_id', 'event_specialty_id', 'day_id', 'hour_id', 'birthday', 'sex', 'phone', 'phone2']), [
            'full_name' => 'احمد  بن علي', 'patient_id' => 99999,
        ]));
    }

    public function test_event_edit_cannot_delete_booked_specialty(): void
    {
        [$a] = $this->esIds();
        $this->patient([[$a, 'today']]);

        $this->deleteJson("/api/events/{$this->eventId}/specialties/{$a}")->assertStatus(409);
        $this->assertDatabaseHas('event_specialties', ['id' => $a]);
    }

    // ------------------------------------------------- public "my turn"

    public function test_my_turn_by_patient_number_or_phone(): void
    {
        [$a, $b] = $this->esIds();
        $ahead = $this->patient([[$a, 'today']], null, '0777000111');
        $this->checkIn($ahead['patient_id']);
        $p = $this->patient([[$a, 'today']], 'سارة بن علي', '0555 12 34 56');
        $this->checkIn($p['patient_id']);
        // Same phone, another family member
        $child = $this->patient([[$b, 'today']], 'يوسف بن علي', '0555123456');
        $this->app['auth']->forgetGuards();
        $url = '/api/public/my-turn';

        // Patient number alone
        $this->postJson($url, ['event_id' => $this->eventId, 'patient_id' => $p['patient_id']])
            ->assertOk()
            ->assertJsonCount(1, 'patients')
            ->assertJsonPath('patients.0.first_name', 'سارة')
            ->assertJsonPath('patients.0.appointments.0.people_before', 1)
            ->assertJsonMissingPath('patients.0.appointments.0.phone');

        // Phone alone, any format: both family members
        $res = $this->postJson($url, ['event_id' => $this->eventId, 'phone' => '+213 555 123 456'])->assertOk();
        $this->assertEqualsCanonicalizing([$p['patient_id'], $child['patient_id']], collect($res->json('patients'))->pluck('patient_id')->all());

        // Neither, unknown number, unknown phone
        $this->postJson($url, ['event_id' => $this->eventId])->assertStatus(422);
        $this->postJson($url, ['event_id' => $this->eventId, 'patient_id' => 424242])->assertNotFound();
        $this->postJson($url, ['event_id' => $this->eventId, 'phone' => '0699999999'])->assertNotFound();
    }

    // ------------------------------------------------- live refresh, stats

    public function test_live_version_changes_when_queue_changes(): void
    {
        [$a] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        $before = $this->getJson("/api/events/{$this->eventId}/live-version")->json('version');
        $this->checkIn($p['patient_id']);

        $this->assertNotSame($before, $this->getJson("/api/events/{$this->eventId}/live-version")->json('version'));

        // Opening or closing a specialty refreshes the screens too
        $before = $this->getJson("/api/events/{$this->eventId}/live-version")->json('version');
        $this->toggle($this->esIds()[3]);
        $this->assertNotSame($before, $this->getJson("/api/events/{$this->eventId}/live-version")->json('version'));
    }

    public function test_statistics_use_one_definition(): void
    {
        [$a, $b] = $this->esIds();
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id']);

        $stats = $this->getJson("/api/appointments/event-statistics/{$this->eventId}")->assertOk()->json('statistics');

        $this->assertSame(['pending' => 1, 'waiting' => 1, 'in_queue' => 1, 'dilatation' => 0, 'in_visit' => 0, 'completed' => 0, 'total' => 3,
            'patients_total' => 2, 'patients_arrived' => 1, 'patients_finished' => 0], $stats);
        $this->assertSame($stats['total'], $this->getJson("/api/{$this->eventId}/statistics")->json('total_appointments'));
    }

    // ------------------------------------------- دخل (with the doctor), timing

    private function enter(array $ids, Doctor $doctor)
    {
        $this->markArrived($doctor, Appointment::find($ids[0])->event_specialty_id);

        return $this->postJson('/api/waitinglist/enter', ['ids' => $ids, 'doctor_id' => $doctor->id]);
    }

    private function arrive(int $esId, Doctor $doctor): void
    {
        $this->postJson("/api/event-specialties/{$esId}/doctors/{$doctor->id}/arrive")->assertOk();
    }

    public function test_enter_leaves_queue_and_locks_the_doctor(): void
    {
        [$a] = $this->esIds();
        $p1 = $this->patient([[$a, 'today']]);
        $p2 = $this->patient([[$a, 'today']], null, '0555000001');
        $p3 = $this->patient([[$a, 'today']], null, '0555000002');
        foreach ([$p1, $p2, $p3] as $p) {
            $this->checkIn($p['patient_id']);
        }
        $drA = $this->doctorFor($a, 'A');
        $drB = $this->doctorFor($a, 'B');

        // إجتاز needs دخل first
        $this->postJson("/api/waitinglist/Complete/{$p1['ids'][0]}")->assertStatus(422);

        $this->enter([$p2['ids'][0]], $drA)->assertOk();
        $this->assertSame('Entered', $this->statusOf($p2['ids'][0]));
        $this->assertSame([$p1['patient_id'], $p3['patient_id']], $this->queue($a));
        $this->assertSame($drA->id, Appointment::find($p2['ids'][0])->doctor_id);

        // Busy doctor refused, another doctor of the same room accepted
        $this->enter([$p1['ids'][0]], $drA)->assertStatus(409);
        $this->enter([$p1['ids'][0]], $drB)->assertOk();

        // Cancel: back to the head of the queue, doctor free again
        $this->postJson("/api/waitinglist/cancel-enter/{$p2['ids'][0]}")->assertOk();
        $this->assertSame([$p2['patient_id'], $p3['patient_id']], $this->queue($a));
        $this->assertNull(Appointment::find($p2['ids'][0])->doctor_id);
        $this->enter([$p3['ids'][0]], $drA)->assertOk();
    }

    public function test_entered_patient_is_not_sent_elsewhere_and_passes_on(): void
    {
        [$a, $b] = $this->esIds();
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $this->checkIn($p['patient_id']);
        $this->enter([$p['ids'][0]], $this->doctorFor($a))->assertOk();

        // Still one active specialty: the other one keeps waiting
        $this->assertSame('Waiting', $this->statusOf($p['ids'][1]));

        $this->postJson("/api/waitinglist/Complete/{$p['ids'][0]}")->assertOk();
        $this->assertSame('Completed', $this->statusOf($p['ids'][0]));
        $this->assertSame('Present', $this->statusOf($p['ids'][1]));
    }

    public function test_family_enters_and_passes_together_with_split_time(): void
    {
        [$a] = $this->esIds();
        $family = [];
        foreach (range(1, 3) as $i) {
            $family[] = $this->patient([[$a, 'today']], null, '+213 555 77 88 99');
            $this->checkIn(end($family)['patient_id']);
        }
        $ids = array_map(fn ($p) => $p['ids'][0], $family);
        $doctor = $this->doctorFor($a);

        Carbon::setTestNow(Carbon::now());
        $this->enter($ids, $doctor)->assertOk();
        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $this->postJson("/api/waitinglist/Complete/{$ids[1]}")->assertOk();
        Carbon::setTestNow();

        foreach ($ids as $id) {
            $row = Appointment::find($id);
            $this->assertSame('Completed', $row->status);
            $this->assertEqualsWithDelta(600, $row->visit_seconds, 2);
        }
    }

    public function test_relatives_by_phone(): void
    {
        [$a, $b] = $this->esIds();
        $p = $this->patient([[$a, 'today']], 'Father', '0555 44 33 22');
        $queued = $this->patient([[$a, 'today']], 'Son', '+213555443322');
        $notArrived = $this->patient([[$a, 'today']], 'Daughter', '0555443322');
        $elsewhere = $this->patient([[$b, 'today']], 'Mother', '0555443322');
        $stranger = $this->patient([[$a, 'today']], 'Other', '0555999999');
        // The fixture gives everyone the same second phone
        Appointment::where('event_id', $this->eventId)->update(['phone2' => '']);
        foreach ([$p, $queued, $elsewhere, $stranger] as $x) {
            $this->checkIn($x['patient_id']);
        }

        $relatives = collect($this->getJson("/api/waitinglist/relatives/{$p['ids'][0]}")->assertOk()->json('relatives'))->keyBy('patient_id');

        $this->assertCount(3, $relatives);
        $this->assertTrue($relatives[$queued['patient_id']]['can_enter']);
        $this->assertSame(2, $relatives[$queued['patient_id']]['place']);
        $this->assertSame('لم يصل بعد', $relatives[$notArrived['patient_id']]['reason']);
        $this->assertFalse($relatives[$elsewhere['patient_id']]['can_enter']);
    }

    /** «غادر» only stops new patients being sent: allowed during a visit, which then ends normally. */
    public function test_doctor_can_leave_with_a_patient_inside(): void
    {
        [$a] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id']);
        $doctor = $this->doctorFor($a);
        $this->arrive($a, $doctor);
        $this->enter([$p['ids'][0]], $doctor)->assertOk();

        $this->postJson("/api/event-specialties/{$a}/doctors/{$doctor->id}/leave")->assertOk();
        $this->postJson("/api/waitinglist/Complete/{$p['ids'][0]}")->assertOk();
        $this->assertSame('Completed', $this->statusOf($p['ids'][0]));
    }

    public function test_doctor_on_a_break_sees_nobody_and_invites_nobody(): void
    {
        [$a] = $this->esIds();
        $specialtyId = DB::table('event_specialties')->where('id', $a)->value('specialty_id');
        $p = $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id']);
        $doctor = $this->doctorFor($a);
        $this->arrive($a, $doctor);
        $invited = fn () => collect($this->getJson("/api/public/waitinglist?event_id={$this->eventId}")->assertOk()->json("data.{$specialtyId}"))
            ->where('invited', true)->count();
        $this->assertSame(1, $invited());

        $this->postJson("/api/event-specialties/{$a}/doctors/{$doctor->id}/break")->assertOk();
        $this->assertSame(0, $invited());
        $this->enter([$p['ids'][0]], $doctor)->assertStatus(422);

        $this->postJson("/api/event-specialties/{$a}/doctors/{$doctor->id}/arrive")->assertOk();
        $this->assertSame(1, $invited());
        $this->enter([$p['ids'][0]], $doctor)->assertOk();
    }

    public function test_doctor_on_a_break_with_a_patient_inside_still_passes_them(): void
    {
        [$a] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id']);
        $doctor = $this->doctorFor($a);
        $this->arrive($a, $doctor);
        $this->enter([$p['ids'][0]], $doctor)->assertOk();

        $this->postJson("/api/event-specialties/{$a}/doctors/{$doctor->id}/break")->assertOk();
        $this->postJson("/api/waitinglist/Complete/{$p['ids'][0]}")->assertOk();
        $this->assertSame('Completed', $this->statusOf($p['ids'][0]));
    }

    public function test_doctor_visits_numbers_alarm_and_idle(): void
    {
        // The test moves the clock 75 minutes on: start at 10:00 so it never crosses midnight
        Carbon::setTestNow(Carbon::now(QueueService::TIMEZONE)->setTime(10, 0));
        [$a] = $this->esIds();
        $patients = [];
        foreach (range(1, 3) as $i) {
            $patients[] = $this->patient([[$a, 'today']], null, "055500010{$i}");
            $this->checkIn(end($patients)['patient_id']);
        }
        $doctor = $this->doctorFor($a); // 20-minute visits: alarm after 30 min
        $start = Carbon::now();
        Carbon::setTestNow($start);
        $this->arrive($a, $doctor);

        $this->enter([$patients[0]['ids'][0]], $doctor)->assertOk();
        Carbon::setTestNow($start->copy()->addMinutes(10));
        $this->postJson("/api/waitinglist/Complete/{$patients[0]['ids'][0]}")->assertOk();

        $this->enter([$patients[1]['ids'][0]], $doctor)->assertOk();
        Carbon::setTestNow($start->copy()->addMinutes(75));

        $row = collect($this->getJson("/api/events/{$this->eventId}/doctor-visits")->assertOk()->json('doctors'))
            ->firstWhere('doctor_id', $doctor->id);
        $this->assertSame(1, $row['treated']);
        $this->assertEquals(10, $row['mean_minutes']);
        $this->assertSame(20, $row['planned_minutes']);
        $this->assertSame(1800, $row['current']['alarm_after_seconds']);
        $this->assertTrue($row['current']['alarm']);

        // Free for 15 minutes while a patient waits: idle
        $this->postJson("/api/waitinglist/Complete/{$patients[1]['ids'][0]}")->assertOk();
        Carbon::setTestNow($start->copy()->addMinutes(90));
        $row = collect($this->getJson("/api/events/{$this->eventId}/doctor-visits")->json('doctors'))
            ->firstWhere('doctor_id', $doctor->id);
        $this->assertNull($row['current']);
        $this->assertTrue($row['idle']);
        Carbon::setTestNow();
    }

    public function test_hall_screen_invites_next_patient_shows_inside_until_passed(): void
    {
        [$a] = $this->esIds();
        $specialtyId = DB::table('event_specialties')->where('id', $a)->value('specialty_id');
        $p1 = $this->patient([[$a, 'today']]);
        $p2 = $this->patient([[$a, 'today']]);
        $this->checkIn($p1['patient_id']);
        $this->checkIn($p2['patient_id']);
        $doctor = $this->doctorFor($a);
        $screen = fn () => $this->getJson("/api/public/waitinglist?event_id={$this->eventId}")->assertOk();
        $invited = fn ($res) => collect($res->json("data.{$specialtyId}"))->where('invited', true)->pluck('patient_id')->all();
        $inside = fn ($res) => collect($res->json("calling.{$specialtyId}"))->pluck('patient_id')->all();

        // No doctor yet: nobody invited
        $this->assertSame([], $invited($screen()));

        // Doctor free: the first in line is invited («تفضل بالدخول»)
        $this->arrive($a, $doctor);
        $this->assertSame([$p1['patient_id']], $invited($screen()));

        // دخل: «عند الطبيب», stays after 30 s; the doctor is busy so nobody else is invited
        $this->enter([$p1['ids'][0]], $doctor)->assertOk();
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $res = $screen();
        $this->assertSame([$p1['patient_id']], $inside($res));
        $this->assertSame([], $invited($res));

        // إجتاز: gone from the screen; the next one is invited
        $this->postJson("/api/waitinglist/Complete/{$p1['ids'][0]}")->assertOk();
        $res = $screen();
        $this->assertSame([], $inside($res));
        $this->assertSame([$p2['patient_id']], $invited($res));
        Carbon::setTestNow();
    }

    public function test_hall_screen_lists_as_many_rows_as_asked(): void
    {
        [$a] = $this->esIds();
        $specialtyId = DB::table('event_specialties')->where('id', $a)->value('specialty_id');
        foreach (range(1, 3) as $i) {
            $this->checkIn($this->patient([[$a, 'today']])['patient_id']);
        }
        $url = "/api/public/waitinglist?event_id={$this->eventId}";

        $res = $this->getJson("{$url}&limit=2")->assertOk();
        $this->assertCount(2, $res->json("data.{$specialtyId}"));
        $this->assertSame(3, $res->json("counts.{$specialtyId}"));

        // Default 10; out-of-range values are clamped (1..50)
        $this->assertCount(3, $this->getJson($url)->json("data.{$specialtyId}"));
        $this->assertCount(1, $this->getJson("{$url}&limit=0")->json("data.{$specialtyId}"));
        $this->assertCount(3, $this->getJson("{$url}&limit=999")->json("data.{$specialtyId}"));
    }

    /** Organizer: live statistics «أثناء الحدث» only (and the public hall screen), changes nothing. */
    public function test_organizer_sees_only_live_statistics_and_changes_nothing(): void
    {
        [$a] = $this->esIds();
        $p = $this->patient([[$a, 'today']]);
        $this->checkIn($p['patient_id']);
        $doctor = $this->doctorFor($a);
        $specialtyId = DB::table('event_specialties')->where('id', $a)->value('specialty_id');

        Sanctum::actingAs($this->staff(User::ROLE_ORGANIZER));
        // The live statistics page reads these
        $this->getJson("/api/events/{$this->eventId}")->assertOk();
        $this->getJson("/api/appointments/event-statistics/{$this->eventId}")->assertOk();
        $this->getJson("/api/appointments/by-event?event_id={$this->eventId}")->assertOk();
        $this->getJson("/api/events/{$this->eventId}/appointment-distribution")->assertOk();
        $this->getJson("/api/events/{$this->eventId}/doctor-visits")->assertOk();
        $this->getJson("/api/public/waitinglist?event_id={$this->eventId}")->assertOk();
        // No specialty lists, presence desk or doctors' board
        $this->getJson("/api/events/{$this->eventId}/stations")->assertForbidden();
        $this->getJson("/api/waitinglist/GetWaitingListBySpeciality/{$this->eventId}/{$specialtyId}")->assertForbidden();
        $this->getJson("/api/event-specialties/{$a}/doctors-present")->assertForbidden();
        $this->getJson("/api/event-specialties/{$a}/dilatation-room")->assertForbidden();
        $this->getJson("/api/events/{$this->eventId}/doctors-today")->assertForbidden();
        $this->getJson("/api/events/{$this->eventId}/duplicates")->assertForbidden();
        $this->getJson("/api/appointments/search?event_id={$this->eventId}&q=1")->assertForbidden();

        $this->enter([$p['ids'][0]], $doctor)->assertForbidden();
        $this->postJson("/api/waitinglist/Commingsoon/{$p['ids'][0]}")->assertForbidden();
        $this->postJson("/api/events/{$this->eventId}/check-in", ['patient_id' => 1])->assertForbidden();
        $this->postJson("/api/event-specialties/{$a}/doctors/{$doctor->id}/arrive")->assertForbidden();
        $this->patchJson("/api/event-specialties/{$a}/toggle-open")->assertForbidden();
        $this->assertSame('Present', $this->statusOf($p['ids'][0]));

        // Another event: refused
        $other = DB::table('events')->insertGetId(['name' => 'Other', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 3]);
        $this->getJson("/api/events/{$other}/doctor-visits")->assertForbidden();
    }

    // ------------------------------------------------- double registrations

    public function test_double_registration_is_proposed_and_merged_into_one_patient(): void
    {
        [$a, $b, $c] = $this->esIds();
        $main = $this->patient([[$a, 'today']], 'أحمد بن علي', '0555 12 34 56');
        $twin = $this->patient([[$b, 'today']], 'احمد  بن علي', '+213555123456');
        $this->patient([[$c, 'today']], 'محمد بن علي', '0555123456'); // same phone, other name
        $this->patient([[$c, 'today']], 'أحمد بن علي', '0777000000');  // same name, other phone

        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));
        $this->getJson("/api/events/{$this->eventId}/patients/{$main['patient_id']}/duplicates")
            ->assertOk()->assertExactJson(['patient_ids' => [$twin['patient_id']]]);

        $groups = $this->getJson("/api/events/{$this->eventId}/duplicates")->assertOk()->json('groups');
        $this->assertCount(1, $groups);
        $this->assertSame([$main['patient_id'], $twin['patient_id']], array_column($groups[0]['patients'], 'patient_id'));
        $this->assertCount(1, $groups[0]['patients'][1]['specialties']);

        $this->postJson("/api/events/{$this->eventId}/patients/{$main['patient_id']}/merge", ['patient_ids' => [$twin['patient_id'] + 1]])
            ->assertStatus(422);
        $this->postJson("/api/events/{$this->eventId}/patients/{$main['patient_id']}/merge", ['patient_ids' => [$twin['patient_id']]])
            ->assertOk();

        $this->assertSame($main['patient_id'], Appointment::find($twin['ids'][0])->patient_id);
        $this->assertDatabaseHas('appointment_logs', ['appointment_id' => $twin['ids'][0], 'action' => 'merged']);
        $this->assertSame([], $this->getJson("/api/events/{$this->eventId}/duplicates")->json('groups'));
        $this->checkIn($main['patient_id'])->assertOk();
        $this->assertSame(1, Appointment::whereIn('id', [$main['ids'][0], $twin['ids'][0]])->where('status', 'Waiting')->count());
    }

    public function test_check_in_refused_above_specialty_limit_until_extra_deleted(): void
    {
        [$a, $b, $c, $d] = $this->esIds();
        $main = $this->patient([[$a, 'today'], [$b, 'today']], 'Sara K', '0555123456');
        $twin = $this->patient([[$c, 'today'], [$d, 'today']], 'sara k', '0555123456');
        $this->postJson("/api/events/{$this->eventId}/patients/{$main['patient_id']}/merge", ['patient_ids' => [$twin['patient_id']]])->assertOk();

        $this->checkIn($main['patient_id'])->assertStatus(422);
        $this->assertSame('Pending', $this->statusOf($main['ids'][0]));

        $this->deleteJson("/api/appointments/{$twin['ids'][1]}")->assertOk();
        $this->checkIn($main['patient_id'])->assertOk();
    }

    public function test_check_in_refused_with_same_specialty_twice(): void
    {
        [$a] = $this->esIds();
        $main = $this->patient([[$a, 'today']], 'Ali B', '0555123456');
        // Same person typed with another birthday: the booking guard could not catch it
        $twin = $this->patient([[$a, 'tomorrow']], 'Ali X', '0555123456');
        Appointment::find($twin['ids'][0])->update(['full_name' => 'Ali B', 'birthday' => '1981-02-02']);
        $this->postJson("/api/events/{$this->eventId}/patients/{$main['patient_id']}/merge", ['patient_ids' => [$twin['patient_id']]])->assertOk();

        $this->checkIn($main['patient_id'])->assertStatus(422);
    }

    public function test_merge_refused_once_the_other_registration_checked_in(): void
    {
        [$a, $b] = $this->esIds();
        $main = $this->patient([[$a, 'today']], 'Ali B', '0555123456');
        $twin = $this->patient([[$b, 'today']], 'Ali B', '0555123456');
        $this->checkIn($twin['patient_id'])->assertOk();

        $this->postJson("/api/events/{$this->eventId}/patients/{$main['patient_id']}/merge", ['patient_ids' => [$twin['patient_id']]])
            ->assertStatus(409);
    }

    public function test_patient_history_lists_status_changes_for_event_staff(): void
    {
        [$a, $b] = $this->esIds();
        $p = $this->patient([[$a, 'today'], [$b, 'today']]);
        $desk = $this->staff(User::ROLE_RECIPIENT_ADMIN);
        Sanctum::actingAs($desk);
        $this->checkIn($p['patient_id'])->assertOk();

        Sanctum::actingAs($this->staff(User::ROLE_SUPER_ADMIN));
        $this->pass($p['ids'][0])->assertOk();

        Sanctum::actingAs($this->staff(User::ROLE_CHECK_ADMIN));
        $logs = collect($this->getJson("/api/events/{$this->eventId}/patients/{$p['patient_id']}/log")->assertOk()->json('logs'))
            ->where('action', 'status_changed')->sortBy('id')->values();

        $first = $logs->firstWhere('appointment_id', $p['ids'][0]);
        $this->assertSame(['Pending', 'Present'], [$first['details']['from'], $first['details']['to']]);
        $this->assertSame('سجّل حضور زائر', $first['details']['via']);
        $this->assertSame('staff', $first['user']);
        $this->assertContains('Entered', $logs->pluck('details.to'));
        $this->assertContains('Completed', $logs->pluck('details.to'));

        // Patients never read it
        Sanctum::actingAs($this->staff(User::ROLE_SIMPLE_USER));
        $this->getJson("/api/events/{$this->eventId}/patients/{$p['patient_id']}/log")->assertForbidden();
    }
}
