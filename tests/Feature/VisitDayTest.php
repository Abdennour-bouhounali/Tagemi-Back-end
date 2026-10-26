<?php

namespace Tests\Feature;

use App\Models\Appointment;
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

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager'] as $id => $name) {
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

    private function toggle(int $esId)
    {
        return $this->patchJson("/api/event-specialties/{$esId}/toggle-open")->assertOk();
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
        $this->checkIn($this->patient([[$b, 'today']])['patient_id']);
        $p = $this->patient([[$a, 'today'], [$b, 'today'], [$c, 'today'], [$this->esIds()[3], 'today']]);
        $this->checkIn($p['patient_id']); // a
        $this->close($this->esIds()[3]);

        $this->postJson("/api/waitinglist/Complete/{$p['ids'][0]}")->assertOk();

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
        $this->postJson("/api/waitinglist/Complete/{$before['ids'][0]}")->assertOk();

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

        $this->postJson("/api/waitinglist/Complete/{$p['ids'][0]}")->assertOk();

        $this->assertSame('Completed', $this->statusOf($p['ids'][0]));
        $this->assertSame([$other['patient_id'], $p['patient_id']], $this->queue($b));

        $this->postJson("/api/waitinglist/Complete/{$p['ids'][1]}")->assertOk()->assertJsonMissingPath('next_appointment');
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
        $this->postJson("/api/waitinglist/Complete/{$p['ids'][1]}")->assertOk();
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
            'phone' => '0555000111', 'phone2' => '0555000222', 'sex' => '0',
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

        $this->assertSame(['pending' => 1, 'waiting' => 1, 'in_queue' => 1, 'dilatation' => 0, 'completed' => 0, 'total' => 3,
            'patients_total' => 2, 'patients_arrived' => 1, 'patients_finished' => 0], $stats);
        $this->assertSame($stats['total'], $this->getJson("/api/{$this->eventId}/statistics")->json('total_appointments'));
    }
}
