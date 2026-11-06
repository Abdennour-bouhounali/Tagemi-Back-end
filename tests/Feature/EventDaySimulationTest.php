<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorAttendance;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A whole visit day played through the API by every staff role: reception
 * check-ins before and after the doctors arrive, officers' دخل / إجتاز,
 * dilatation, referrals, special cases, push downs, super admin changes,
 * duplicate merges, doctors leaving, taking breaks and coming back. Queue and
 * attendance invariants are checked after every action.
 *   DB_DATABASE=tagemi_security_test php artisan test --filter=EventDaySimulationTest
 * SIM_SEEDS=50 plays more random days than the 10 run in CI.
 */
class EventDaySimulationTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;
    /** esId => ['name', 'today' => [dayId, [hourIds]], 'tomorrow' => [dayId, [hourIds]]] */
    private array $es = [];
    /** esId => Doctor[] */
    private array $doctors = [];
    private array $users = [];
    private int $nextPatient = 50000;
    private array $trail = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager', 9 => 'Organizer'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);

        $this->eventId = DB::table('events')->insertGetId([
            'name' => 'Sim', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 2,
        ]);

        // Eyes has a dilatation room and two doctors, the others one doctor
        foreach (['Eyes' => [true, 2], 'Heart' => [false, 1], 'Teeth' => [false, 1], 'Skin' => [false, 1]] as $name => [$dilatation, $nDoctors]) {
            $specialtyId = DB::table('specialties')->insertGetId(['name' => $name . uniqid()]);
            $esId = DB::table('event_specialties')->insertGetId([
                'event_id' => $this->eventId, 'specialty_id' => $specialtyId, 'has_dilatation' => $dilatation, 'opened_on' => null,
            ]);
            $this->es[$esId]['name'] = $name;
            foreach (['today' => 0, 'tomorrow' => 1] as $key => $offset) {
                $dayId = DB::table('days')->insertGetId([
                    'event_specialty_id' => $esId,
                    'day_date' => Carbon::now(QueueService::TIMEZONE)->addDays($offset)->toDateString(),
                ]);
                $hours = [];
                foreach (['08:00:00', '09:00:00', '10:00:00'] as $time) {
                    $hours[] = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => $time, 'max_allowed' => 30, 'counter' => 0]);
                }
                $this->es[$esId][$key] = [$dayId, $hours];
            }
            for ($i = 1; $i <= $nDoctors; $i++) {
                $doctor = Doctor::create([
                    'first_name' => "{$name}{$i}", 'family_name' => 'Dr', 'specialty_id' => $specialtyId,
                    'phone' => '07' . random_int(10000000, 99999999), 'wilaya_code' => '16', 'sector' => 'public', 'is_active' => true, 'sex' => 'male',
                ]);
                DB::table('doctor_intervals')->insert([
                    'day_id' => $this->es[$esId]['today'][0], 'doctor_id' => $doctor->id,
                    'start_time' => '00:00:00', 'end_time' => '23:59:00', 'visit_minutes' => 15, 'visits_count' => 90, 'extra_visits' => 0,
                ]);
                $this->doctors[$esId][] = $doctor;
            }
        }

        foreach (['super' => 1, 'special' => 3, 'officer' => 4, 'reception' => 5, 'manager' => 8, 'organizer' => 9] as $key => $role) {
            $user = User::create(['name' => $key, 'email' => uniqid($key, true) . '@test.local', 'password' => 'password123']);
            $user->forceFill(['role_id' => $role, 'event_id' => $this->eventId])->save();
            $this->users[$key] = $user;
        }
    }

    // ------------------------------------------------------------ helpers

    private function esIds(): array
    {
        return array_keys($this->es);
    }

    private function esNamed(string $name): int
    {
        foreach ($this->es as $id => $es) {
            if ($es['name'] === $name) {
                return $id;
            }
        }
        $this->fail("no specialty {$name}");
    }

    /** @param array $bookings [[esId, 'today'|'tomorrow'], ...] first = primary */
    private function patient(array $bookings, ?string $name = null, ?string $phone = null, ?string $birthday = null): array
    {
        $patientId = ++$this->nextPatient;
        $ids = [];
        foreach ($bookings as $i => [$esId, $when]) {
            [$dayId, $hours] = $this->es[$esId][$when];
            $hourId = $hours[$patientId % count($hours)];
            DB::table('hours')->where('id', $hourId)->increment('counter');
            $ids[] = Appointment::create([
                'user_id' => 1, 'event_id' => $this->eventId, 'event_specialty_id' => $esId,
                'day_id' => $dayId, 'hour_id' => $hourId, 'full_name' => $name ?? "Patient {$patientId}",
                'birthday' => $birthday ?? '1980-01-01', 'sex' => (string) ($patientId % 2), 'phone' => $phone ?? sprintf('0555%06d', $patientId),
                'phone2' => '', 'state' => 'Alger', 'city' => 'Alger',
                'patient_id' => $patientId, 'specialty_order' => $i === 0 ? 1 : 0, 'status' => 'Pending',
            ])->id;
        }

        return ['patient_id' => $patientId, 'ids' => $ids];
    }

    /** Sends a request as one staff member; 5xx always fails the test. */
    private function as(string $who, string $method, string $uri, array $data = [])
    {
        Sanctum::actingAs($this->users[$who]);
        $response = $this->json($method, $uri, $data);
        $this->trail[] = sprintf('%s %s %s %s -> %d %s', $who, $method, $uri, json_encode($data), $response->getStatusCode(), mb_substr((string) $response->json('message'), 0, 90));
        if ($response->getStatusCode() >= 500) {
            $this->fail("Server error\n" . implode("\n", array_slice($this->trail, -15)) . "\n" . $response->getContent());
        }

        return $response;
    }

    private function arrive(Doctor $doctor, int $esId)
    {
        return $this->as('super', 'POST', "/api/event-specialties/{$esId}/doctors/{$doctor->id}/arrive");
    }

    private function checkIn(int $patientId)
    {
        return $this->as('reception', 'POST', "/api/events/{$this->eventId}/check-in", ['patient_id' => $patientId]);
    }

    private function queueIds(int $esId): array
    {
        return app(QueueService::class)->queue($esId)->pluck('id')->all();
    }

    /** Here and able to see patients (not on a break). */
    private function presentDoctors(int $esId): array
    {
        $ids = DB::table('doctor_attendances')->where('day_id', $this->es[$esId]['today'][0])
            ->whereNotNull('arrived_at')->whereNull('left_at')->whereNull('on_break_since')->pluck('doctor_id')->all();

        return array_values(array_filter($this->doctors[$esId], fn ($d) => in_array($d->id, $ids)));
    }

    private function doctorsOnBreak(int $esId): array
    {
        $ids = DB::table('doctor_attendances')->where('day_id', $this->es[$esId]['today'][0])
            ->whereNotNull('on_break_since')->pluck('doctor_id')->all();

        return array_values(array_filter($this->doctors[$esId], fn ($d) => in_array($d->id, $ids)));
    }

    /** Arrived today, present or gone (a doctor who left still finishes the queue). */
    private function arrivedDoctors(int $esId): array
    {
        $ids = DB::table('doctor_attendances')->where('day_id', $this->es[$esId]['today'][0])
            ->whereNotNull('arrived_at')->pluck('doctor_id')->all();

        return array_values(array_filter($this->doctors[$esId], fn ($d) => in_array($d->id, $ids)));
    }

    private function accepts(int $esId): bool
    {
        return DB::table('event_specialties')->where('id', $esId)->whereDate('opened_on', QueueService::today())->exists()
            && !app(QueueService::class)->capacity($esId)['full'];
    }

    private function busy(Doctor $doctor): bool
    {
        return app(QueueService::class)->doctorBusy($doctor->id);
    }

    /**
     * Rules that must hold after every action of the day.
     */
    private function assertInvariants(string $when): void
    {
        $today = QueueService::scopeToday(Appointment::query())->where('event_id', $this->eventId)->get();
        $problems = [];

        foreach ($today->groupBy('patient_id') as $patientId => $rows) {
            $active = $rows->filter(fn ($a) => in_array($a->status, QueueService::ACTIVE_STATUSES, true));
            if ($active->count() > 1) {
                $problems[] = "patient {$patientId} active in {$active->count()} specialties: " . $active->pluck('status', 'id')->toJson();
            }
            $pending = $rows->where('status', 'Pending')->count();
            if ($pending > 0 && $pending < $rows->count()) {
                $problems[] = "patient {$patientId} half checked in: " . $rows->pluck('status', 'id')->toJson();
            }
            // Nobody waits while one of their specialties is open and they are nowhere
            if ($active->isEmpty()) {
                $stranded = $rows->where('status', 'Waiting')->first(fn ($a) => $this->accepts($a->event_specialty_id));
                if ($stranded) {
                    $problems[] = "patient {$patientId} stranded: waits for open specialty {$stranded->event_specialty_id} with no active queue";
                }
            }
        }

        foreach ($this->esIds() as $esId) {
            $queue = $today->where('event_specialty_id', $esId)->filter(fn ($a) => in_array($a->status, QueueService::QUEUE_STATUSES, true))
                ->sortBy([['orderList', 'asc'], ['id', 'asc']])->values();
            $orders = $queue->pluck('orderList')->all();
            if ($orders !== range(1, max(1, count($orders))) && count($orders) > 0) {
                $problems[] = "queue {$this->es[$esId]['name']} orderList not 1..n: " . json_encode($orders);
            }
            // «قدّمه» puts a patient in place 3 counting priority patients (user's choice): no priority-block check
        }

        $notQueued = $today->reject(fn ($a) => in_array($a->status, QueueService::QUEUE_STATUSES, true))->where('orderList', '!=', 0);
        foreach ($notQueued as $a) {
            $problems[] = "appointment {$a->id} ({$a->status}) keeps orderList {$a->orderList}";
        }

        foreach ($today->where('status', 'Entered')->groupBy('doctor_id') as $doctorId => $rows) {
            if (!$doctorId || $rows->pluck('visit_group')->unique()->count() !== 1 || $rows->contains(fn ($a) => !$a->entered_at)) {
                $problems[] = "doctor {$doctorId} has inconsistent visits: " . $rows->pluck('visit_group', 'id')->toJson();
            }
        }

        // Each attendance agrees with its periods: one open period exactly while working
        $dayIds = collect($this->es)->map(fn ($es) => $es['today'][0])->values();
        foreach (DoctorAttendance::with('periods')->whereIn('day_id', $dayIds)->get() as $att) {
            $periods = $att->periods->sortBy('id')->values();
            $open = $periods->whereNull('left_at')->count();
            $label = "attendance {$att->id} (doctor {$att->doctor_id})";
            if ($open !== ($att->isWorking() ? 1 : 0)) {
                $problems[] = "{$label}: {$open} open periods, working=" . json_encode($att->isWorking());
            }
            if ($att->arrived_at && $periods->isEmpty()) {
                $problems[] = "{$label}: arrived with no period";
            }
            if ($att->left_at !== null && $att->on_break_since !== null) {
                $problems[] = "{$label}: left and on a break at once";
            }
            if ($att->isOnBreak() !== (bool) $periods->last()?->ended_by_break) {
                $problems[] = "{$label}: on_break does not match the last period";
            }
            if ($periods->slice(0, -1)->contains(fn ($p) => $p->left_at === null)) {
                $problems[] = "{$label}: an earlier period is still open";
            }
        }

        // Hour counters follow the appointments booked on them
        $counts = Appointment::where('event_id', $this->eventId)->selectRaw('hour_id, COUNT(*) c')->groupBy('hour_id')->pluck('c', 'hour_id');
        foreach ($this->es as $es) {
            foreach (array_merge($es['today'][1], $es['tomorrow'][1]) as $hourId) {
                $counter = (int) DB::table('hours')->where('id', $hourId)->value('counter');
                if ($counter !== (int) ($counts[$hourId] ?? 0)) {
                    $problems[] = "hour {$hourId} counter {$counter} != " . (int) ($counts[$hourId] ?? 0) . ' appointments';
                }
            }
        }

        if ($problems) {
            $this->fail("Invariant broken {$when}:\n- " . implode("\n- ", array_slice($problems, 0, 10))
                . "\nLast actions:\n" . implode("\n", array_slice($this->trail, -15)));
        }
    }

    /** Officers serve every open queue until nobody is left (doctors arrive if needed). */
    private function drain(): void
    {
        foreach ($this->esIds() as $esId) {
            foreach ($this->doctors[$esId] as $doctor) {
                if (!in_array($doctor, $this->presentDoctors($esId), true)) {
                    $this->arrive($doctor, $esId);
                }
            }
        }
        // A queue closed with the toggle while its doctors stay present is reopened by the super admin
        foreach ($this->esIds() as $esId) {
            if (!DB::table('event_specialties')->where('id', $esId)->whereDate('opened_on', QueueService::today())->exists()) {
                $this->as('super', 'PATCH', "/api/event-specialties/{$esId}/toggle-open")->assertOk();
            }
        }
        for ($guard = 0; $guard < 2000; $guard++) {
            $didSomething = false;
            foreach (QueueService::scopeToday(Appointment::query())->where('event_id', $this->eventId)->where('status', 'Entered')->get() as $a) {
                // A family passes together: the others are done once the first one passed
                if (Appointment::whereKey($a->id)->value('status') !== 'Entered') {
                    continue;
                }
                $this->as('officer', 'POST', "/api/waitinglist/Complete/{$a->id}")->assertOk();
                $didSomething = true;
            }
            foreach (QueueService::scopeToday(Appointment::query())->where('event_id', $this->eventId)->whereIn('status', QueueService::DILATATION_STATUSES)->get() as $a) {
                $this->as('officer', 'POST', "/api/waitinglist/FinishedDilatation/{$a->id}")->assertOk();
                $didSomething = true;
            }
            foreach ($this->esIds() as $esId) {
                foreach ($this->presentDoctors($esId) as $doctor) {
                    $head = $this->queueIds($esId)[0] ?? null;
                    if ($head && !$this->busy($doctor)) {
                        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => [$head], 'doctor_id' => $doctor->id])->assertOk();
                        $didSomething = true;
                    }
                }
            }
            $this->assertInvariants('while draining');
            if (!$didSomething) {
                return;
            }
        }
        $this->fail('drain did not finish');
    }

    // ------------------------------------------------- 1. a planned full day

    public function test_full_day_every_patient_is_served(): void
    {
        [$eyes, $heart, $teeth, $skin] = [$this->esNamed('Eyes'), $this->esNamed('Heart'), $this->esNamed('Teeth'), $this->esNamed('Skin')];
        $combos = [[$eyes], [$heart], [$teeth], [$skin], [$eyes, $heart], [$heart, $teeth], [$teeth, $skin], [$skin, $eyes], [$eyes, $teeth]];
        $patients = [];
        for ($i = 0; $i < 60; $i++) {
            $patients[] = $this->patient(array_map(fn ($es) => [$es, 'today'], $combos[$i % count($combos)]));
        }
        $tomorrow = $this->patient([[$heart, 'tomorrow']]);

        // 07:30 reception opens before any doctor: everyone waits
        foreach (array_slice($patients, 0, 25) as $p) {
            $this->checkIn($p['patient_id'])->assertOk()->assertJsonPath('appointment', null);
        }
        $this->checkIn($tomorrow['patient_id'])->assertStatus(422);
        $this->checkIn(999999)->assertStatus(404);
        $this->checkIn($patients[0]['patient_id'])->assertStatus(409);
        $this->assertInvariants('after early check-ins');

        // Doctors arrive one by one: queues open and fill in arrival order
        $this->arrive($this->doctors[$eyes][0], $eyes)->assertOk();
        $this->assertInvariants('eyes opened');
        $this->arrive($this->doctors[$heart][0], $heart)->assertOk();
        $this->arrive($this->doctors[$eyes][0], $eyes)->assertStatus(409); // already present
        $this->assertInvariants('heart opened');

        // More patients while two specialties work
        foreach (array_slice($patients, 25, 20) as $p) {
            $this->checkIn($p['patient_id'])->assertOk();
            $this->assertInvariants("check-in {$p['patient_id']}");
        }

        // Officer serves a few in Eyes, one goes to dilatation and back
        for ($n = 0; $n < 3; $n++) {
            $head = $this->queueIds($eyes)[0];
            $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => [$head], 'doctor_id' => $this->doctors[$eyes][0]->id])->assertOk();
            if ($n === 1) {
                $this->as('officer', 'POST', "/api/waitinglist/Dilatation/{$head}")->assertOk();
                $this->as('officer', 'POST', "/api/waitinglist/DilatationArrived/{$head}")->assertOk();
                $this->as('officer', 'POST', "/api/waitinglist/FinishedDilatation/{$head}")->assertOk();
                $this->assertSame($head, $this->queueIds($eyes)[0], 'back from dilatation = head of the queue');
                continue;
            }
            $this->as('officer', 'POST', "/api/waitinglist/Complete/{$head}")->assertOk();
            $this->assertInvariants("eyes visit {$n}");
        }

        $this->arrive($this->doctors[$teeth][0], $teeth)->assertOk();
        $this->arrive($this->doctors[$skin][0], $skin)->assertOk();
        $this->arrive($this->doctors[$eyes][1], $eyes)->assertOk();
        $this->assertInvariants('all open');

        foreach (array_slice($patients, 45) as $p) {
            $this->checkIn($p['patient_id'])->assertOk();
        }
        $this->assertInvariants('all checked in');

        $this->drain();

        $left = QueueService::scopeToday(Appointment::query())->where('event_id', $this->eventId)->where('status', '!=', 'Completed')->pluck('status', 'id');
        $this->assertSame([], $left->all(), 'every appointment of today is Completed at the end of the day');
        $this->assertSame('Pending', Appointment::find($tomorrow['ids'][0])->status);
    }

    // ------------------------------- 2. random day: all roles, any order

    public static function seeds(): array
    {
        $seeds = [11, 2026, 777, 4, 99, 1, 31, 404, 5150, 8080];
        for ($n = count($seeds); $n < (int) (getenv('SIM_SEEDS') ?: 0); $n++) {
            $seeds[] = 10000 + $n;
        }

        return collect($seeds)->mapWithKeys(fn ($n) => ["seed {$n}" => [$n]])->all();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('seeds')]
    public function test_random_day_keeps_queues_consistent(int $seed): void
    {
        mt_srand($seed);
        $esIds = $this->esIds();
        $patients = [];
        // Families share a phone; a few people registered twice (same name + phone)
        for ($i = 0; $i < 70; $i++) {
            $n = mt_rand(1, 2);
            $picked = (array) array_rand(array_flip($esIds), $n);
            shuffle($picked);
            $phone = $i % 7 === 0 && $i > 0 ? sprintf('0555%06d', $this->nextPatient) : null;
            $patients[] = $this->patient(array_map(fn ($es) => [$es, mt_rand(0, 9) ? 'today' : 'tomorrow'], $picked), null, $phone);
        }
        foreach ([0, 1, 2] as $k) {
            $p = $this->patient([[$esIds[$k], 'today']], "Twin Person {$k}", "0661{$k}00000");
            $patients[] = $p;
            $patients[] = $this->patient([[$esIds[$k + 1], 'today']], "Twin Person {$k}", "0661{$k}00000");
        }
        $ids = fn () => QueueService::scopeToday(Appointment::query())->where('event_id', $this->eventId);

        $actions = [
            'checkin' => 22, 'arrive' => 5, 'leave' => 2, 'break' => 3, 'back' => 3, 'hall' => 2, 'toggle' => 1, 'enter' => 18, 'complete' => 16, 'cancel' => 2,
            'push' => 4, 'pushmany' => 1, 'special' => 2, 'front' => 2, 'dil_send' => 3, 'dil_recv' => 3, 'dil_done' => 3,
            'refer' => 2, 'delete' => 1, 'reschedule' => 2, 'addspec' => 1, 'merge' => 2, 'family' => 3, 'organizer' => 2,
        ];
        $bag = [];
        foreach ($actions as $name => $weight) {
            $bag = array_merge($bag, array_fill(0, $weight, $name));
        }
        $pick = fn (array $xs) => $xs ? $xs[mt_rand(0, count($xs) - 1)] : null;

        for ($step = 0; $step < 600; $step++) {
            $action = $pick($bag);
            $es = $pick($esIds);
            switch ($action) {
                case 'checkin':
                    $p = $pick($patients);
                    $expected = $this->expectCheckIn($p['patient_id']);
                    $this->checkIn($p['patient_id'])->assertStatus($expected);
                    break;
                case 'arrive':
                    $d = $pick($this->doctors[$es]);
                    $this->arrive($d, $es);
                    break;
                case 'leave':
                    $d = $pick($this->presentDoctors($es));
                    if ($d) {
                        // Allowed with a patient inside: only new patients stop
                        $this->as('super', 'POST', "/api/event-specialties/{$es}/doctors/{$d->id}/leave")->assertOk();
                    }
                    break;
                case 'break':
                    $d = $pick($this->presentDoctors($es));
                    if ($d) {
                        $before = DB::table('event_specialties')->where('id', $es)->value('opened_on');
                        $this->as('super', 'POST', "/api/event-specialties/{$es}/doctors/{$d->id}/break")->assertOk();
                        $this->assertSame($before, DB::table('event_specialties')->where('id', $es)->value('opened_on'), 'a break changed the queue');
                        $head = $this->queueIds($es)[0] ?? null;
                        if ($head) {
                            $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => [$head], 'doctor_id' => $d->id])->assertStatus($this->busy($d) ? 409 : 422);
                        }
                    }
                    break;
                case 'back':
                    $d = $pick($this->doctorsOnBreak($es));
                    if ($d) {
                        // Most come back, some go home straight from the break
                        $this->as('super', 'POST', "/api/event-specialties/{$es}/doctors/{$d->id}/" . (mt_rand(0, 3) ? 'arrive' : 'leave'))->assertOk();
                    }
                    break;
                case 'hall':
                    $hall = $this->as('super', 'GET', "/api/public/waitinglist?event_id={$this->eventId}")->assertOk();
                    $sid = DB::table('event_specialties')->where('id', $es)->value('specialty_id');
                    $free = count(array_filter($this->presentDoctors($es), fn ($d) => !$this->busy($d)));
                    $invited = collect($hall->json("data.{$sid}"))->where('invited', true)->count();
                    $this->assertLessThanOrEqual($free, $invited, "hall invites more visitors than free doctors (seed {$seed})");
                    break;
                case 'toggle':
                    // Reopening needs a present doctor
                    $expected = $this->accepts($es) || DB::table('event_specialties')->where('id', $es)->whereDate('opened_on', QueueService::today())->exists() || $this->presentDoctors($es) ? 200 : 422;
                    $this->as('super', 'PATCH', "/api/event-specialties/{$es}/toggle-open")->assertStatus($expected);
                    break;
                case 'enter':
                    $d = $pick($this->arrivedDoctors($es));
                    $head = $this->queueIds($es)[mt_rand(0, 1)] ?? null;
                    if ($d && $head) {
                        $expected = $this->busy($d) ? 409 : (in_array($d, $this->doctorsOnBreak($es)) ? 422 : 200);
                        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => [$head], 'doctor_id' => $d->id])->assertStatus($expected);
                    }
                    break;
                case 'family':
                    $head = $this->queueIds($es)[0] ?? null;
                    $d = $pick(array_values(array_filter($this->presentDoctors($es), fn ($d) => !$this->busy($d))));
                    if ($head && $d) {
                        $rel = collect($this->as('officer', 'GET', "/api/waitinglist/relatives/{$head}")->assertOk()->json('relatives'))
                            ->where('can_enter', true)->pluck('id')->all();
                        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => array_merge([$head], $rel), 'doctor_id' => $d->id])->assertOk();
                    }
                    break;
                case 'complete':
                    $a = $pick($ids()->where('status', 'Entered')->pluck('id')->all());
                    if ($a) {
                        $this->as('officer', 'POST', "/api/waitinglist/Complete/{$a}")->assertOk();
                    }
                    break;
                case 'cancel':
                    $a = $pick($ids()->where('status', 'Entered')->pluck('id')->all());
                    if ($a) {
                        $this->as('officer', 'POST', "/api/waitinglist/cancel-enter/{$a}")->assertOk();
                    }
                    break;
                case 'push':
                    $a = $pick($this->queueIds($es));
                    if ($a) {
                        $this->as('manager', 'POST', "/api/waitinglist/Commingsoon/{$a}", ['places' => mt_rand(1, 8)])->assertOk();
                    }
                    break;
                case 'pushmany':
                    $q = $this->queueIds($es);
                    if (count($q) > 2) {
                        $this->as('manager', 'POST', '/api/waitinglist/push-down', ['appointment_ids' => array_slice($q, 0, 2)])->assertOk();
                    }
                    break;
                case 'special':
                    $a = $pick($ids()->whereIn('status', ['Pending', 'Waiting', 'Present', 'Commingsoon'])->pluck('id')->all());
                    if ($a) {
                        $this->as('special', 'POST', "/api/waitinglist/Special/{$a}");
                    }
                    break;
                case 'front':
                    $a = $pick($ids()->whereIn('status', ['Pending', 'Waiting', 'Present', 'Commingsoon'])->pluck('id')->all());
                    if ($a) {
                        $this->as($pick(['special', 'super']), 'POST', "/api/waitinglist/move-to-front/{$a}");
                    }
                    break;
                case 'dil_send':
                    $a = $pick(array_merge($this->queueIds($this->esNamed('Eyes')), $ids()->where('status', 'Entered')->where('event_specialty_id', $this->esNamed('Eyes'))->pluck('id')->all()));
                    if ($a) {
                        $this->as('officer', 'POST', "/api/waitinglist/Dilatation/{$a}")->assertOk();
                    }
                    break;
                case 'dil_recv':
                    $a = $pick($ids()->where('status', 'DilatationSent')->pluck('id')->all());
                    if ($a) {
                        $this->as('officer', 'POST', "/api/waitinglist/DilatationArrived/{$a}")->assertOk();
                    }
                    break;
                case 'dil_done':
                    $a = $pick($ids()->whereIn('status', QueueService::DILATATION_STATUSES)->pluck('id')->all());
                    if ($a) {
                        $this->as('officer', 'POST', "/api/waitinglist/FinishedDilatation/{$a}")->assertOk();
                    }
                    break;
                case 'refer':
                    $a = $pick($this->queueIds($es));
                    if ($a) {
                        $options = $this->as('officer', 'GET', "/api/appointments/{$a}/referral-options")->assertOk()->json('specialties');
                        if ($options) {
                            $this->as('officer', 'POST', "/api/appointments/{$a}/refer", ['event_specialty_id' => $pick($options)['event_specialty_id']])->assertOk();
                        }
                    }
                    break;
                case 'delete':
                    $a = $pick(Appointment::where('event_id', $this->eventId)->whereNotIn('status', ['Entered', 'DilatationSent', 'Dilatation'])->pluck('id')->all());
                    if ($a) {
                        $this->as('special', 'DELETE', "/api/appointments/{$a}")->assertOk();
                    }
                    break;
                case 'reschedule':
                    $a = Appointment::where('event_id', $this->eventId)->whereIn('status', ['Pending', 'Waiting'])->inRandomOrder()->first();
                    if ($a) {
                        $when = $pick(['today', 'tomorrow']);
                        [$dayId, $hours] = $this->es[$a->event_specialty_id][$when];
                        $hourId = $pick(array_values(array_diff($hours, [$a->hour_id])));
                        $this->as('super', 'PATCH', "/api/appointments/{$a->id}/reschedule", ['day_id' => $dayId, 'hour_id' => $hourId])->assertOk();
                    }
                    break;
                case 'addspec':
                    $p = $pick($patients);
                    $source = Appointment::where('event_id', $this->eventId)->where('patient_id', $p['patient_id'])->first();
                    if ($source) {
                        [$dayId, $hours] = $this->es[$es]['today'];
                        $this->as('super', 'POST', "/api/appointments/{$source->id}/add-specialty", ['event_specialty_id' => $es, 'day_id' => $dayId, 'hour_id' => $hours[0]]);
                    }
                    break;
                case 'merge':
                    $groups = $this->as('reception', 'GET', "/api/events/{$this->eventId}/duplicates")->assertOk()->json('groups');
                    $g = $pick($groups);
                    if ($g) {
                        [$keep, $other] = $g['patients'];
                        $this->as('reception', 'POST', "/api/events/{$this->eventId}/patients/{$keep['patient_id']}/merge", ['patient_ids' => [$other['patient_id']]]);
                    }
                    break;
                case 'organizer':
                    $a = $pick($this->queueIds($es));
                    if ($a) {
                        // Runs the specialties (below), not the presence desk or the patient list
                        $this->as('organizer', 'POST', "/api/events/{$this->eventId}/check-in", ['patient_id' => 1])->assertStatus(403);
                        $this->as('organizer', 'POST', "/api/waitinglist/Commingsoon/{$a}", ['places' => 2])->assertOk();
                    }
                    $d = $pick(array_values(array_filter($this->presentDoctors($es), fn ($d) => !$this->busy($d))));
                    $head = $this->queueIds($es)[0] ?? null;
                    if ($d && $head) {
                        $this->as('organizer', 'POST', '/api/waitinglist/enter', ['ids' => [$head], 'doctor_id' => $d->id])->assertOk();
                        $this->as('organizer', 'POST', "/api/waitinglist/Complete/{$head}")->assertOk();
                    }
                    $this->as('organizer', 'GET', "/api/events/{$this->eventId}/stations")->assertOk();
                    $this->as('organizer', 'GET', "/api/events/{$this->eventId}/doctor-visits")->assertOk();
                    break;
            }
            $this->assertInvariants("step {$step} ({$action}, seed {$seed})");
        }

        // End of the day: every checked-in patient's today appointments get served
        $this->drain();
        $left = $ids()->whereNotIn('status', ['Completed', 'Pending'])->get(['id', 'patient_id', 'status', 'event_specialty_id']);
        $this->assertSame([], $left->toArray(), "checked-in patients left unserved (seed {$seed})\n" . implode("\n", array_slice($this->trail, -10)));
    }

    /** Expected HTTP status of a check-in, from the data (not from the code under test). */
    private function expectCheckIn(int $patientId): int
    {
        $all = Appointment::where('event_id', $this->eventId)->where('patient_id', $patientId)->get();
        $today = QueueService::scopeToday(Appointment::query())->where('event_id', $this->eventId)->where('patient_id', $patientId)->get();
        if ($all->isEmpty()) {
            return 404;
        }
        if ($today->isEmpty()) {
            return 422;
        }
        if ($today->contains(fn ($a) => $a->status !== 'Pending')) {
            return 409;
        }
        // Appointments added past the limit (marked beyond_limit) are not counted
        if ($all->where('beyond_limit', false)->count() > 2 || $all->pluck('event_specialty_id')->duplicates()->isNotEmpty()) {
            return 422;
        }

        return 200;
    }

    // ------------------------------------------------- 3. who may do what

    public function test_role_matrix_for_visit_day_actions(): void
    {
        $eyes = $this->esNamed('Eyes');
        $this->arrive($this->doctors[$eyes][0], $eyes)->assertOk();
        $p = $this->patient([[$eyes, 'today']]);
        $this->checkIn($p['patient_id'])->assertOk();
        $a = $p['ids'][0];
        $doctor = $this->doctors[$eyes][0]->id;

        // [method, uri, body, roles allowed]
        $cases = [
            ['POST', "/api/events/{$this->eventId}/check-in", ['patient_id' => 1], ['super', 'special', 'reception']],
            ['POST', "/api/waitinglist/Commingsoon/{$a}", [], ['super', 'manager', 'organizer']],
            ['POST', '/api/waitinglist/push-down', ['appointment_ids' => [$a]], ['super', 'manager', 'organizer']],
            ['POST', '/api/waitinglist/enter', ['ids' => [$a], 'doctor_id' => $doctor], ['super', 'officer', 'organizer']],
            ['POST', "/api/waitinglist/Special/{$a}", [], ['super', 'special']],
            ['POST', "/api/waitinglist/move-to-front/{$a}", [], ['super', 'special']],
            ['DELETE', "/api/appointments/{$a}", [], ['super', 'special']],
            ['PATCH', "/api/appointments/{$a}/reschedule", [], ['super']],
            ['PATCH', "/api/event-specialties/{$eyes}/toggle-open", [], ['super']],
            ['POST', "/api/event-specialties/{$eyes}/doctors/{$doctor}/leave", [], ['super']],
            ['GET', "/api/events/{$this->eventId}/stations", [], ['super', 'officer', 'manager', 'organizer']],
            ['GET', "/api/events/{$this->eventId}/doctors-today", [], ['super']],
            ['GET', "/api/events/{$this->eventId}/duplicates", [], ['super', 'special', 'reception']],
            ['GET', "/api/appointments/event-statistics/{$this->eventId}", [], ['super', 'special', 'reception', 'organizer']],
        ];

        $wrong = [];
        foreach ($cases as [$method, $uri, $body, $allowed]) {
            foreach (array_keys($this->users) as $who) {
                // Only the refusal is checked: a real call would change the state for the next role
                if (in_array($who, $allowed, true)) {
                    continue;
                }
                $status = $this->as($who, $method, $uri, $body)->getStatusCode();
                if ($status !== 403) {
                    $wrong[] = "{$who} {$method} {$uri} -> {$status} (expected 403)";
                }
            }
        }
        $this->assertSame([], $wrong);

        // Staff of another event can't touch this one
        $otherEvent = DB::table('events')->insertGetId(['name' => 'Other', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 2]);
        foreach (['officer', 'reception', 'manager'] as $who) {
            $this->users[$who]->forceFill(['event_id' => $otherEvent])->save();
        }
        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => [$a], 'doctor_id' => $doctor])->assertStatus(403);
        $this->as('manager', 'POST', '/api/waitinglist/push-down', ['appointment_ids' => [$a]])->assertStatus(403);
        $this->as('reception', 'POST', "/api/events/{$this->eventId}/check-in", ['patient_id' => $p['patient_id']])->assertStatus(403);
        $this->as('officer', 'GET', "/api/waitinglist/GetWaitingListBySpeciality/{$this->eventId}/1")->assertStatus(403);
    }

    // ------------------------------------------- 4. suspected weak spots

    /** A special case set while the patient is with a doctor in another specialty. */
    public function test_special_case_while_with_a_doctor_elsewhere(): void
    {
        [$eyes, $heart] = [$this->esNamed('Eyes'), $this->esNamed('Heart')];
        $this->arrive($this->doctors[$eyes][0], $eyes);
        $this->arrive($this->doctors[$heart][0], $heart);
        $p = $this->patient([[$eyes, 'today'], [$heart, 'today']]);
        $this->checkIn($p['patient_id'])->assertOk();
        $active = Appointment::whereIn('id', $p['ids'])->where('status', 'Present')->first();
        $other = Appointment::whereIn('id', $p['ids'])->where('id', '!=', $active->id)->first();
        $doctor = $this->doctors[$active->event_specialty_id][0];
        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => [$active->id], 'doctor_id' => $doctor->id])->assertOk();

        $this->as('special', 'POST', "/api/waitinglist/Special/{$other->id}")->assertStatus(422);
        $this->assertInvariants('special case while Entered elsewhere');
        $this->assertSame('Waiting', Appointment::find($other->id)->status);
    }

    /**
     * The last doctor leaves with patients in the queue: the queue closes for
     * new patients, the doctor stays on the officer's screen and finishes
     * those already in it. Reopening needs a present doctor.
     */
    public function test_last_doctor_leaving_finishes_the_queue_and_reopens_only_with_a_doctor(): void
    {
        $heart = $this->esNamed('Heart');
        $doctor = $this->doctors[$heart][0];
        $this->arrive($doctor, $heart);
        $p1 = $this->patient([[$heart, 'today']]);
        $p2 = $this->patient([[$heart, 'today']]);
        $this->checkIn($p1['patient_id']);
        $this->checkIn($p2['patient_id']);

        $this->as('super', 'POST', "/api/event-specialties/{$heart}/doctors/{$doctor->id}/leave")->assertOk()->assertJsonPath('is_open', false);

        $shown = $this->as('officer', 'GET', "/api/event-specialties/{$heart}/doctors-present")->assertOk()->json('doctors');
        $this->assertSame([$doctor->id], array_column($shown, 'doctor_id'), 'the doctor who left stays on the officer screen');
        $this->assertNotNull($shown[0]['left_at']);

        // New patient: not sent to the closed queue
        $p3 = $this->patient([[$heart, 'today']]);
        $this->checkIn($p3['patient_id'])->assertOk()->assertJsonPath('appointment', null);
        $this->assertSame('Waiting', Appointment::find($p3['ids'][0])->status);

        // The 2 already in it are seen by the doctor who left
        foreach ([$p1, $p2] as $p) {
            $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => $p['ids'], 'doctor_id' => $doctor->id])->assertOk();
            $this->as('officer', 'POST', "/api/waitinglist/Complete/{$p['ids'][0]}")->assertOk();
        }

        // Warning on the officer's list and the hall screen
        $list = $this->as('officer', 'GET', "/api/waitinglist/GetWaitingListBySpeciality/{$this->eventId}/" . DB::table('event_specialties')->where('id', $heart)->value('specialty_id'))->assertOk();
        $this->assertFalse($list->json('is_open'));
        $hall = $this->getJson("/api/public/waitinglist?event_id={$this->eventId}")->assertOk();
        $this->assertContains((int) DB::table('event_specialties')->where('id', $heart)->value('specialty_id'), $hall->json('closed_specialties'));

        // Reopen: refused without a doctor, works once one is present again
        $this->as('super', 'PATCH', "/api/event-specialties/{$heart}/toggle-open")->assertStatus(422);
        $this->arrive($doctor, $heart)->assertOk()->assertJsonPath('is_open', true);
        $this->assertSame('Present', Appointment::find($p3['ids'][0])->status);
        $this->assertInvariants('after reopening');
    }

    /**
     * One of two doctors leaves: the specialty takes new patients only up to
     * the visits planned for the doctor still working, then it is full.
     */
    public function test_doctor_leaving_limits_the_specialty_to_the_remaining_doctors_planned_visits(): void
    {
        $eyes = $this->esNamed('Eyes');
        [$stays, $leaves] = $this->doctors[$eyes];
        DB::table('doctor_intervals')->where('doctor_id', $stays->id)->update(['visits_count' => 3]);
        $this->arrive($stays, $eyes);
        $this->arrive($leaves, $eyes);

        // Before anyone leaves there is no limit
        $ps = [];
        for ($i = 0; $i < 2; $i++) {
            $ps[] = $p = $this->patient([[$eyes, 'today']]);
            $this->checkIn($p['patient_id'])->assertOk();
        }
        $this->as('super', 'POST', "/api/event-specialties/{$eyes}/doctors/{$leaves->id}/leave")->assertOk()->assertJsonPath('is_open', true);

        // Load 2 < 3 planned for the doctor who stays: one more is sent, then full
        $third = $this->patient([[$eyes, 'today']]);
        $this->checkIn($third['patient_id'])->assertOk()->assertJsonPath('appointment.id', $third['ids'][0]);
        $fourth = $this->patient([[$eyes, 'today']]);
        $this->checkIn($fourth['patient_id'])->assertOk()->assertJsonPath('appointment', null);
        $this->assertSame('Waiting', Appointment::find($fourth['ids'][0])->status);

        $list = $this->as('officer', 'GET', "/api/waitinglist/GetWaitingListBySpeciality/{$this->eventId}/" . DB::table('event_specialties')->where('id', $eyes)->value('specialty_id'))->assertOk();
        $this->assertTrue($list->json('is_open'));
        $this->assertSame(['limited' => true, 'full' => true, 'capacity' => 3, 'load' => 3], $list->json('capacity'));
        $station = collect($this->as('officer', 'GET', "/api/events/{$this->eventId}/stations")->json('stations'))->firstWhere('event_specialty_id', $eyes);
        $this->assertTrue($station['is_full']);
        $this->assertContains((int) DB::table('event_specialties')->where('id', $eyes)->value('specialty_id'), $this->getJson("/api/public/waitinglist?event_id={$this->eventId}")->json('closed_specialties'));

        // Referral into a full specialty is refused
        $heart = $this->esNamed('Heart');
        $this->arrive($this->doctors[$heart][0], $heart);
        $other = $this->patient([[$heart, 'today']]);
        $this->checkIn($other['patient_id']);
        $this->as('officer', 'POST', "/api/appointments/{$other['ids'][0]}/refer", ['event_specialty_id' => $eyes])->assertStatus(409);

        // Both still in it are served (one by the doctor who left)
        $q = $this->queueIds($eyes);
        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => [$q[0]], 'doctor_id' => $leaves->id])->assertOk();
        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => [$q[1]], 'doctor_id' => $stays->id])->assertOk();
        $this->assertInvariants('full specialty');
    }

    /** Referral / added specialty for a person registered twice (not merged) who holds it under the other number. */
    public function test_referral_to_specialty_held_under_another_number_is_refused_cleanly(): void
    {
        [$eyes, $heart] = [$this->esNamed('Eyes'), $this->esNamed('Heart')];
        $this->arrive($this->doctors[$eyes][0], $eyes);
        $this->arrive($this->doctors[$heart][0], $heart);
        $first = $this->patient([[$eyes, 'today']], 'Same Person', '0661000111');
        $this->patient([[$heart, 'today']], 'Same Person', '0661000111');
        $this->checkIn($first['patient_id'])->assertOk();

        $options = $this->as('officer', 'GET', "/api/appointments/{$first['ids'][0]}/referral-options")->json('specialties');
        $this->assertNotContains($heart, array_column($options, 'event_specialty_id'));
        $this->as('officer', 'POST', "/api/appointments/{$first['ids'][0]}/refer", ['event_specialty_id' => $heart])->assertStatus(409);
        [$dayId, $hours] = $this->es[$heart]['tomorrow'];
        $this->as('super', 'POST', "/api/appointments/{$first['ids'][0]}/add-specialty", ['event_specialty_id' => $heart, 'day_id' => $dayId, 'hour_id' => $hours[0]])->assertStatus(409);
        $this->assertInvariants('after refused referral');
    }

    /** «قدّمه»: place 3 counting special cases, [A, B, C, X, Y] + Z -> [A, B, Z, C, X, Y]. */
    public function test_move_to_front_counts_special_cases_in_the_three_places(): void
    {
        $heart = $this->esNamed('Heart');
        $this->arrive($this->doctors[$heart][0], $heart);
        $ids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $p = $this->patient([[$heart, 'today']], "Special {$name}");
            $this->as('special', 'POST', "/api/waitinglist/Special/{$p['ids'][0]}")->assertOk();
            $ids[$name] = $p['ids'][0];
        }
        foreach (['X', 'Y', 'Z'] as $name) {
            $p = $this->patient([[$heart, 'today']], "Normal {$name}");
            $this->checkIn($p['patient_id'])->assertOk();
            $ids[$name] = $p['ids'][0];
        }
        $this->as('special', 'POST', "/api/waitinglist/move-to-front/{$ids['Z']}")->assertOk();

        $this->assertSame([$ids['A'], $ids['B'], $ids['Z'], $ids['C'], $ids['X'], $ids['Y']], $this->queueIds($heart));
        $this->assertFalse((bool) Appointment::find($ids['Z'])->is_special, 'no special-case mark');
    }

    /** Doctors and the patient manager open the dilatation room to look; only the officer acts there. */
    public function test_dilatation_room_is_visible_to_doctors_and_patient_manager(): void
    {
        $eyes = $this->esNamed('Eyes');
        $this->arrive($this->doctors[$eyes][0], $eyes);
        $p = $this->patient([[$eyes, 'today']]);
        $this->checkIn($p['patient_id']);
        $this->as('officer', 'POST', "/api/waitinglist/Dilatation/{$p['ids'][0]}")->assertOk();

        $doctorUser = User::create(['name' => 'doc', 'email' => uniqid('doc', true) . '@test.local', 'password' => 'password123']);
        $doctorUser->forceFill(['role_id' => 6, 'event_id' => $this->eventId])->save();
        $this->users['doctor'] = $doctorUser;

        foreach (['doctor', 'manager'] as $who) {
            $this->as($who, 'GET', "/api/event-specialties/{$eyes}/dilatation-room")->assertOk()->assertJsonCount(1, 'coming');
            $this->as($who, 'POST', "/api/waitinglist/DilatationArrived/{$p['ids'][0]}")->assertStatus(403);
        }

        // The organizer runs it like the officer
        $this->as('organizer', 'GET', "/api/event-specialties/{$eyes}/dilatation-room")->assertOk()->assertJsonCount(1, 'coming');
        $this->as('organizer', 'POST', "/api/waitinglist/DilatationArrived/{$p['ids'][0]}")->assertOk();
    }

    /** A relative who comes late joins the visit in progress; they pass together, time split from the start. */
    public function test_late_relative_joins_the_visit_in_progress(): void
    {
        $skin = $this->esNamed('Skin');
        $doctor = $this->doctors[$skin][0];
        $this->arrive($doctor, $skin);
        $mother = $this->patient([[$skin, 'today']], 'Mother', '0770555111');
        $child = $this->patient([[$skin, 'today']], 'Child', '0770555111');
        $other = $this->patient([[$skin, 'today']]);
        foreach ([$mother, $other] as $p) {
            $this->checkIn($p['patient_id']);
        }
        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => $mother['ids'], 'doctor_id' => $doctor->id])->assertOk();

        // The child arrives 6 minutes later
        $this->travel(6)->minutes();
        $this->checkIn($child['patient_id'])->assertOk();
        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => $child['ids'], 'doctor_id' => $doctor->id])->assertStatus(409);
        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => $child['ids'], 'doctor_id' => $doctor->id, 'join' => true])
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'الزيارة الجارية'));
        $this->assertSame(
            Appointment::find($mother['ids'][0])->visit_group,
            Appointment::find($child['ids'][0])->visit_group,
        );
        $this->assertInvariants('after late join');

        $this->travel(4)->minutes();
        $this->as('officer', 'POST', "/api/waitinglist/Complete/{$mother['ids'][0]}")->assertOk();
        // 10 minutes from the first one in, split between the two
        $this->assertSame(300, Appointment::find($mother['ids'][0])->visit_seconds);
        $this->assertSame(300, Appointment::find($child['ids'][0])->visit_seconds);
        $this->assertSame('Completed', Appointment::find($child['ids'][0])->status);

        // No visit in progress: «join» simply starts a new one
        $this->as('officer', 'POST', '/api/waitinglist/enter', ['ids' => $other['ids'], 'doctor_id' => $doctor->id, 'join' => true])->assertOk();
        $this->assertSame('Entered', Appointment::find($other['ids'][0])->status);
    }

    /** Before the visit day the doctors page lists the next visit day's doctors (nothing to record yet). */
    public function test_doctors_page_shows_the_next_visit_day_before_the_event(): void
    {
        $eventId = DB::table('events')->insertGetId(['name' => 'Later', 'date' => Carbon::now(QueueService::TIMEZONE)->addDays(3)->toDateString(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 1]);
        $specialtyId = DB::table('specialties')->insertGetId(['name' => 'Later' . uniqid()]);
        $esId = DB::table('event_specialties')->insertGetId(['event_id' => $eventId, 'specialty_id' => $specialtyId]);
        $visitDay = Carbon::now(QueueService::TIMEZONE)->addDays(3)->toDateString();
        $dayId = DB::table('days')->insertGetId(['event_specialty_id' => $esId, 'day_date' => $visitDay]);
        $doctor = Doctor::create(['first_name' => 'Later', 'family_name' => 'Dr', 'specialty_id' => $specialtyId, 'wilaya_code' => '16', 'sector' => 'public', 'is_active' => true, 'sex' => 'male']);
        DB::table('doctor_intervals')->insert(['day_id' => $dayId, 'doctor_id' => $doctor->id, 'start_time' => '08:00:00', 'end_time' => '12:00:00', 'visit_minutes' => 15, 'visits_count' => 16, 'extra_visits' => 0]);

        $res = $this->as('super', 'GET', "/api/events/{$eventId}/doctors-today")->assertOk()
            ->assertJsonPath('date', $visitDay)->assertJsonPath('is_today', false);
        $this->assertSame([$doctor->id], array_column($res->json('specialties.0.doctors'), 'doctor_id'));

        // On a visit day it is today's doctors, as before
        $this->as('super', 'GET', "/api/events/{$this->eventId}/doctors-today")->assertOk()->assertJsonPath('is_today', true);
    }

    /** A real double click is still processed once. */
    public function test_double_click_is_still_processed_once(): void
    {
        config(['app.duplicate_request_window' => 3]);
        $teeth = $this->esNamed('Teeth');
        $this->arrive($this->doctors[$teeth][0], $teeth);
        $ps = [];
        for ($i = 0; $i < 8; $i++) {
            $ps[] = $this->patient([[$teeth, 'today']]);
            $this->checkIn(end($ps)['patient_id']);
        }
        $first = $ps[0]['ids'][0];
        $this->as('manager', 'POST', "/api/waitinglist/Commingsoon/{$first}")->assertOk();
        $this->as('manager', 'POST', "/api/waitinglist/Commingsoon/{$first}")->assertOk()->assertHeader('X-Duplicate-Request', 'replayed');
        $this->assertSame(6, array_search($first, $this->queueIds($teeth)) + 1);
    }

    /** Pushing the same patient down twice in a row (e.g. 5 + 5 places). */
    public function test_two_identical_push_downs_a_few_seconds_apart(): void
    {
        config(['app.duplicate_request_window' => 3]);
        $teeth = $this->esNamed('Teeth');
        $this->arrive($this->doctors[$teeth][0], $teeth);
        $ps = [];
        for ($i = 0; $i < 12; $i++) {
            $ps[] = $this->patient([[$teeth, 'today']]);
            $this->checkIn(end($ps)['patient_id']);
        }
        $first = $ps[0]['ids'][0];
        $this->as('manager', 'POST', "/api/waitinglist/Commingsoon/{$first}")->assertOk();
        $this->travel(4)->seconds();
        $this->as('manager', 'POST', "/api/waitinglist/Commingsoon/{$first}")->assertOk();
        $place = array_search($first, $this->queueIds($teeth)) + 1;
        $this->assertSame(11, $place, "pushed down twice by 5 should be at place 11, is at {$place}");
    }

    /** دخل by mistake, «إلغاء الدخول», then دخل again with the same doctor. */
    public function test_enter_cancel_enter_again(): void
    {
        config(['app.duplicate_request_window' => 3]);
        $skin = $this->esNamed('Skin');
        $doctor = $this->doctors[$skin][0];
        $this->arrive($doctor, $skin);
        $p = $this->patient([[$skin, 'today']]);
        $this->checkIn($p['patient_id']);
        $a = $p['ids'][0];

        $body = ['ids' => [$a], 'doctor_id' => $doctor->id];
        $this->as('officer', 'POST', '/api/waitinglist/enter', $body)->assertOk();
        $this->as('officer', 'POST', "/api/waitinglist/cancel-enter/{$a}")->assertOk();
        $this->travel(4)->seconds();
        $this->as('officer', 'POST', '/api/waitinglist/enter', $body)->assertOk();
        $this->assertSame('Entered', Appointment::find($a)->status, 'second دخل answered OK but was not executed');
    }

    /** Reception corrects the phone of a patient booked in two specialties. */
    public function test_editing_patient_data_updates_all_their_appointments(): void
    {
        [$eyes, $heart] = [$this->esNamed('Eyes'), $this->esNamed('Heart')];
        $p = $this->patient([[$eyes, 'today'], [$heart, 'today']]);
        $this->as('reception', 'PUT', "/api/appointments/{$p['ids'][0]}", ['phone' => '0770123456'])->assertOk();
        $phones = Appointment::whereIn('id', $p['ids'])->pluck('phone')->unique()->values()->all();
        $this->assertSame(['0770123456'], $phones, 'one appointment still has the old phone');
    }

    /** A referral pushes the patient above the event's specialty limit; later actions must still work. */
    public function test_referral_then_add_specialty_and_reschedule(): void
    {
        [$eyes, $heart, $teeth] = [$this->esNamed('Eyes'), $this->esNamed('Heart'), $this->esNamed('Teeth')];
        foreach ([$eyes, $heart, $teeth] as $es) {
            $this->arrive($this->doctors[$es][0], $es);
        }
        $p = $this->patient([[$eyes, 'today'], [$heart, 'today']]);
        $this->checkIn($p['patient_id'])->assertOk();
        $active = Appointment::whereIn('id', $p['ids'])->where('status', 'Present')->first();
        $this->as('officer', 'POST', "/api/appointments/{$active->id}/refer", ['event_specialty_id' => $teeth])->assertOk();
        $this->assertInvariants('after referral above the limit');
        $this->drain();
        $this->assertSame(3, Appointment::where('patient_id', $p['patient_id'])->where('status', 'Completed')->count());
    }

    /** Reception deletes extra specialties (after a merge) only before check-in, never the last one. */
    public function test_reception_deletes_specialties_only_before_check_in(): void
    {
        [$eyes, $heart, $teeth] = [$this->esNamed('Eyes'), $this->esNamed('Heart'), $this->esNamed('Teeth')];
        $this->arrive($this->doctors[$eyes][0], $eyes);
        // Merged patient holding 3 specialties, the event allows 2
        $p = $this->patient([[$eyes, 'today'], [$heart, 'today'], [$teeth, 'today']]);
        $this->checkIn($p['patient_id'])->assertStatus(422);

        $this->as('reception', 'DELETE', "/api/appointments/{$p['ids'][2]}")->assertOk();
        $this->assertNull(Appointment::find($p['ids'][2]));
        $this->checkIn($p['patient_id'])->assertOk();

        // Checked in: reception may no longer delete
        $this->as('reception', 'DELETE', "/api/appointments/{$p['ids'][1]}")->assertForbidden();
        $this->assertNotNull(Appointment::find($p['ids'][1]));

        // Never the patient's only specialty
        $single = $this->patient([[$heart, 'tomorrow']]);
        $this->as('reception', 'DELETE', "/api/appointments/{$single['ids'][0]}")->assertForbidden();
        $this->assertInvariants('after reception deletes');
    }

    /** Specialty officer registers an existing patient in a specialty; past the limit only with the event switch. */
    public function test_specialty_officer_registers_existing_patient_past_limit_when_switched_on(): void
    {
        [$eyes, $heart, $teeth] = [$this->esNamed('Eyes'), $this->esNamed('Heart'), $this->esNamed('Teeth')];
        foreach ([$eyes, $heart, $teeth] as $es) {
            $this->arrive($this->doctors[$es][0], $es);
        }

        // Within the limit (1 of 2): always allowed, not marked beyond_limit
        $one = $this->patient([[$eyes, 'today']]);
        $this->checkIn($one['patient_id'])->assertOk();
        $this->as('officer', 'GET', "/api/event-specialties/{$heart}/register-patient/{$one['patient_id']}")
            ->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('at_limit', false);
        $this->as('officer', 'POST', "/api/event-specialties/{$heart}/register-patient", ['patient_id' => $one['patient_id']])
            ->assertCreated()->assertJsonPath('appointment.beyond_limit', false);
        $this->as('officer', 'POST', "/api/event-specialties/{$heart}/register-patient", ['patient_id' => $one['patient_id']])->assertStatus(422);

        // At the limit (2 of 2): refused while the switch is off
        $two = $this->patient([[$eyes, 'today'], [$heart, 'today']]);
        $this->checkIn($two['patient_id'])->assertOk();
        $this->as('officer', 'GET', "/api/event-specialties/{$teeth}/register-patient/{$two['patient_id']}")
            ->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('may_exceed', false);
        $this->as('officer', 'POST', "/api/event-specialties/{$teeth}/register-patient", ['patient_id' => $two['patient_id']])->assertStatus(422);

        // Super admin switches it on for the event's specialty officers
        DB::table('events')->where('id', $this->eventId)->update(['specialty_admins_can_exceed_limit' => true]);
        $res = $this->as('officer', 'POST', "/api/event-specialties/{$teeth}/register-patient", ['patient_id' => $two['patient_id']])
            ->assertCreated()->assertJsonPath('appointment.beyond_limit', true);
        $new = Appointment::find($res->json('appointment.id'));
        $this->assertSame(3, Appointment::where('patient_id', $two['patient_id'])->count());
        $this->assertNotSame('Pending', $new->status); // checked in today: joins the patient's day
        $this->assertSame(1, DB::table('appointment_logs')->where('appointment_id', $new->id)->where('action', 'specialty_added')->count());

        // Other roles can't use it; unknown number is a clean 404
        foreach (['reception', 'manager', 'special'] as $who) {
            $this->as($who, 'POST', "/api/event-specialties/{$teeth}/register-patient", ['patient_id' => $one['patient_id']])->assertForbidden();
        }
        $this->as('officer', 'POST', "/api/event-specialties/{$teeth}/register-patient", ['patient_id' => 999999])->assertNotFound();
        $this->assertInvariants('after officer registrations');
    }
}
