<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\EventSpecialty;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Doctors' arrival/departure on the visit day: the first arrival opens the
 * specialty's queue, the last departure closes it.
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class DoctorAttendanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $specialtyId;
    private int $esId;
    private int $eventId;
    private Doctor $drA;
    private Doctor $drB;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 4 => 'CheckAdmin'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        // Booking code (training patients too) stores user_id = 1; CI starts from an empty database
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);
        $this->specialtyId = DB::table('specialties')->insertGetId(['name' => 'عيون' . uniqid()]);
        $this->drA = $this->doctor('أ');
        $this->drB = $this->doctor('ب');

        $this->admin = User::create(['name' => 'a', 'email' => uniqid('a', true) . '@test.local', 'password' => 'password123']);
        $this->admin->forceFill(['role_id' => 1])->save();
        Sanctum::actingAs($this->admin);

        $today = QueueService::today();
        $day = fn (Doctor $d, string $start, string $end) => ['doctor_id' => $d->id, 'days' => [['day_date' => $today, 'intervals' => [
            ['start_time' => $start, 'end_time' => $end, 'visit_minutes' => 20],
        ]]]];
        $this->eventId = $this->postJson('/api/events', [
            'name' => 'زيارة', 'date' => $today,
            'specialties' => [['id' => $this->specialtyId, 'schedule' => [$day($this->drA, '08:00', '12:00'), $day($this->drB, '10:00', '13:00')]]],
        ])->assertCreated()->json('event.id');
        $this->esId = DB::table('event_specialties')->where('event_id', $this->eventId)->value('id');
    }

    private function doctor(string $name): Doctor
    {
        return Doctor::create([
            'first_name' => $name, 'family_name' => 'طبيب', 'phone' => '07' . random_int(10000000, 99999999),
            'wilaya_code' => '16', 'specialty_id' => $this->specialtyId, 'sector' => 'public', 'is_active' => true,
        ]);
    }

    private function isOpen(): bool
    {
        return EventSpecialty::find($this->esId)->isOpenToday();
    }

    public function test_first_arrival_opens_and_last_departure_closes(): void
    {
        $today = $this->getJson("/api/events/{$this->eventId}/doctors-today")->assertOk()->json('specialties.0.doctors');
        $this->assertCount(2, $today);
        $this->assertFalse($this->isOpen());

        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive")->assertOk();
        $this->assertTrue($this->isOpen());
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive")->assertStatus(409);

        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drB->id}/arrive")->assertOk();
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/leave")->assertOk()->assertJsonPath('is_open', true);
        $this->assertTrue($this->isOpen());

        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drB->id}/leave")->assertOk()->assertJsonPath('is_open', false);
        $this->assertFalse($this->isOpen());

        $row = collect($this->getJson("/api/events/{$this->eventId}/doctors-today")->json('specialties.0.doctors'))->firstWhere('doctor_id', $this->drA->id);
        $this->assertNotNull($row['arrived_at']);
        $this->assertNotNull($row['left_at']);
        $this->assertFalse($row['present']);
    }

    private function at(string $clock): void
    {
        Carbon::setTestNow(Carbon::parse(QueueService::today() . ' ' . $clock, QueueService::TIMEZONE));
    }

    private function row(Doctor $doctor, ?string $url = null): array
    {
        $doctors = $url
            ? $this->getJson($url)->assertOk()->json('doctors')
            : $this->getJson("/api/events/{$this->eventId}/doctors-today")->assertOk()->json('specialties.0.doctors');

        return collect($doctors)->firstWhere('doctor_id', $doctor->id);
    }

    public function test_a_doctor_can_leave_and_come_back_several_times_in_a_day(): void
    {
        $arrive = "/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive";
        $leave = "/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/leave";

        $this->at('08:00');
        $this->postJson($arrive)->assertOk();
        $this->at('10:00');
        $this->postJson($leave)->assertOk()->assertJsonPath('is_open', false);
        $this->assertFalse($this->isOpen());

        $this->at('10:30');
        $this->postJson($arrive)->assertOk();
        $this->assertTrue($this->isOpen());
        $this->at('12:00');
        $this->postJson($leave)->assertOk();
        $this->at('13:30');
        $this->postJson($arrive)->assertOk();
        $this->postJson($arrive)->assertStatus(409); // no empty period from a double click

        $row = $this->row($this->drA);
        $this->assertSame([
            ['arrived_at' => '08:00', 'left_at' => '10:00', 'break' => false],
            ['arrived_at' => '10:30', 'left_at' => '12:00', 'break' => false],
            ['arrived_at' => '13:30', 'left_at' => null, 'break' => false],
        ], $row['periods']);
        $this->assertSame('08:00', $row['arrived_at']);
        $this->assertSame('13:30', $row['present_since']);
        $this->assertNull($row['left_at']);
        $this->assertTrue($row['present']);

        $this->at('15:00');
        $this->postJson($leave)->assertOk();
        $row = $this->row($this->drA);
        $this->assertSame(['arrived_at' => '13:30', 'left_at' => '15:00', 'break' => false], $row['periods'][2]);
        $this->assertSame('15:00', $row['left_at']);
        $this->assertNull($row['present_since']);

        $history = $this->getJson("/api/doctors/{$this->drA->id}/history")->assertOk();
        $history->assertJsonCount(3, 'days.0.periods')->assertJsonPath('summary.days_present', 1);
    }

    public function test_time_away_on_a_break_does_not_count_as_idle(): void
    {
        $arrive = "/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive";

        $this->at('08:00');
        $this->postJson($arrive)->assertOk();
        $this->at('09:00');
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/leave")->assertOk();
        $this->at('11:00');
        $this->postJson($arrive)->assertOk();
        $this->at('11:04');

        $row = $this->row($this->drA, "/api/event-specialties/{$this->esId}/doctors-present");
        $this->assertSame(4, $row['idle_minutes']);
        $this->assertSame('11:00', $row['present_since']);
        $this->assertCount(2, $row['periods']);
    }

    public function test_a_doctor_added_on_the_day_gets_a_period_and_can_return(): void
    {
        $drC = $this->doctor('ج');
        $this->at('09:00');
        $this->postJson("/api/event-specialties/{$this->esId}/doctors", ['doctor_ids' => [$drC->id], 'end_time' => '23:59', 'visit_minutes' => 20])->assertOk();
        $this->at('10:00');
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$drC->id}/leave")->assertOk();
        $this->at('11:00');
        $this->postJson("/api/event-specialties/{$this->esId}/doctors", ['doctor_ids' => [$drC->id], 'end_time' => '23:59', 'visit_minutes' => 20])->assertOk();

        $this->assertSame([
            ['arrived_at' => '09:00', 'left_at' => '10:00', 'break' => false],
            ['arrived_at' => '11:00', 'left_at' => null, 'break' => false],
        ], $this->row($drC)['periods']);
    }

    private function url(Doctor $doctor, string $action): string
    {
        return "/api/event-specialties/{$this->esId}/doctors/{$doctor->id}/{$action}";
    }

    public function test_a_break_keeps_the_queue_open_and_ends_with_the_return(): void
    {
        $this->at('08:00');
        $this->postJson($this->url($this->drA, 'break'))->assertStatus(409); // not arrived
        $this->postJson($this->url($this->drA, 'arrive'))->assertOk();

        $this->at('12:00');
        $this->postJson($this->url($this->drA, 'break'))->assertOk()->assertJsonPath('is_open', true);
        $this->postJson($this->url($this->drA, 'break'))->assertStatus(409);
        $this->assertTrue($this->isOpen()); // the only doctor is out, but coming back

        $row = $this->row($this->drA);
        $this->assertTrue($row['on_break']);
        $this->assertFalse($row['present']);
        $this->assertSame('12:00', $row['on_break_since']);
        $this->assertNull($row['left_at']);
        $this->assertNull($row['present_since']);

        $this->at('13:00');
        $this->postJson($this->url($this->drA, 'arrive'))->assertOk();
        $row = $this->row($this->drA);
        $this->assertFalse($row['on_break']);
        $this->assertTrue($row['present']);
        $this->assertSame([
            ['arrived_at' => '08:00', 'left_at' => '12:00', 'break' => true],
            ['arrived_at' => '13:00', 'left_at' => null, 'break' => false],
        ], $row['periods']);
    }

    public function test_a_break_does_not_limit_capacity_but_a_departure_does(): void
    {
        $this->postJson($this->url($this->drA, 'arrive'))->assertOk();
        $this->postJson($this->url($this->drB, 'arrive'))->assertOk();
        $queues = app(QueueService::class);

        $this->postJson($this->url($this->drA, 'break'))->assertOk();
        $this->assertFalse($queues->capacity($this->esId)['limited']);

        $this->postJson($this->url($this->drB, 'leave'))->assertOk()->assertJsonPath('is_open', true); // A is coming back
        $capacity = $queues->capacity($this->esId);
        $this->assertTrue($capacity['limited']);
        $this->assertSame(12, $capacity['capacity']); // A's planned visits still count
    }

    public function test_leaving_from_a_break_closes_the_queue_at_the_break_time(): void
    {
        $this->at('08:00');
        $this->postJson($this->url($this->drA, 'arrive'))->assertOk();
        $this->at('12:00');
        $this->postJson($this->url($this->drA, 'break'))->assertOk();
        $this->at('14:00');
        $this->postJson($this->url($this->drA, 'leave'))->assertOk()->assertJsonPath('is_open', false);

        $row = $this->row($this->drA);
        $this->assertFalse($row['on_break']);
        $this->assertSame('12:00', $row['left_at']);
        $this->assertSame([['arrived_at' => '08:00', 'left_at' => '12:00', 'break' => false]], $row['periods']);
    }

    public function test_a_queue_does_not_reopen_with_every_doctor_on_a_break(): void
    {
        $this->postJson($this->url($this->drA, 'arrive'))->assertOk();
        $this->postJson($this->url($this->drA, 'break'))->assertOk();
        $this->patchJson("/api/event-specialties/{$this->esId}/toggle-open")->assertOk(); // closes
        $this->patchJson("/api/event-specialties/{$this->esId}/toggle-open")->assertStatus(422);

        // Picking them again in «أضف طبيبًا» ends the break
        $this->postJson("/api/event-specialties/{$this->esId}/doctors", ['doctor_ids' => [$this->drA->id], 'end_time' => '23:59', 'visit_minutes' => 20])->assertOk();
        $this->assertTrue($this->row($this->drA)['present']);
        $this->assertTrue($this->isOpen());
    }

    public function test_unscheduled_doctor_and_other_roles_are_refused(): void
    {
        $other = $this->doctor('ج');
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$other->id}/arrive")->assertStatus(422);
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/leave")->assertStatus(409);

        $checkAdmin = User::create(['name' => 'c', 'email' => uniqid('c', true) . '@test.local', 'password' => 'password123']);
        $checkAdmin->forceFill(['role_id' => 4, 'event_id' => $this->eventId])->save();
        Sanctum::actingAs($checkAdmin);
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive")->assertForbidden();
    }

    public function test_history_lists_participations_with_times(): void
    {
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/arrive")->assertOk();
        $this->postJson("/api/event-specialties/{$this->esId}/doctors/{$this->drA->id}/leave")->assertOk();

        $history = $this->getJson("/api/doctors/{$this->drA->id}/history")->assertOk();
        $history->assertJsonPath('summary.events', 1)->assertJsonPath('summary.days_present', 1)->assertJsonPath('summary.planned_visits', 12);
        $history->assertJsonPath('days.0.event_name', 'زيارة')->assertJsonPath('days.0.intervals.0', '08:00–12:00');
        $this->assertNotNull($history->json('days.0.arrived_at'));
        $this->assertNotNull($history->json('days.0.left_at'));
    }

    public function test_opening_adds_a_doctor_not_planned_in_the_event(): void
    {
        $drC = $this->doctor('ج');
        $url = "/api/event-specialties/{$this->esId}/doctors";

        $this->postJson($url, ['doctor_ids' => [$drC->id], 'end_time' => '00:00', 'visit_minutes' => 20])->assertStatus(422);
        $this->postJson($url, ['doctor_ids' => [$drC->id], 'end_time' => '23:59', 'visit_minutes' => 20])->assertOk();

        $this->assertTrue($this->isOpen());
        $c = collect($this->getJson("/api/events/{$this->eventId}/doctors-today")->json('specialties.0.doctors'))
            ->firstWhere('doctor_id', $drC->id);
        $this->assertTrue($c['present']);
        $this->assertStringEndsWith('23:59', $c['intervals'][0]);

        // A planned doctor in the list just arrives (no second interval); twice is harmless
        $this->postJson($url, ['doctor_ids' => [$drA = $this->drA->id, $drC->id], 'end_time' => '23:59', 'visit_minutes' => 20])->assertOk();
        $this->assertSame(1, DB::table('doctor_intervals')->where('doctor_id', $drA)->count());
        $this->assertSame(1, DB::table('doctor_intervals')->where('doctor_id', $drC->id)->count());

        // Booking hours are not changed by a doctor added on the day
        $this->assertSame(0, DB::table('hours')->join('days', 'days.id', '=', 'hours.day_id')
            ->where('days.event_specialty_id', $this->esId)->where('hours.time', '>=', '13:00:00')->count());
    }

    public function test_training_event_specialty_opens_with_added_doctor_who_can_see_patients(): void
    {
        foreach (\App\Models\Event::where('is_simulation', true)->get() as $existing) {
            app(\App\Services\TrainingEventService::class)->delete($existing);
        }
        while (DB::table('specialties')->count() < 8) {
            DB::table('specialties')->insert(['name' => 'T' . uniqid()]);
        }
        $event = app(\App\Services\TrainingEventService::class)->start();
        $es = EventSpecialty::where('event_id', $event->id)->orderBy('id')->first();
        $doctor = $this->doctor('د');

        $this->postJson("/api/event-specialties/{$es->id}/doctors", ['doctor_ids' => [$doctor->id], 'end_time' => '23:59', 'visit_minutes' => 15])->assertOk();
        $this->assertTrue($es->fresh()->isOpenToday());

        // The added doctor is present; the training event's own doctors have not arrived yet
        $present = collect($this->getJson("/api/event-specialties/{$es->id}/doctors-present")->assertOk()->json('doctors'));
        $this->assertSame([$doctor->id], $present->where('present', true)->pluck('doctor_id')->all());
        $this->assertCount(count(\App\Services\TrainingEventService::DOCTORS[0]) + 1, $present);
    }
}
