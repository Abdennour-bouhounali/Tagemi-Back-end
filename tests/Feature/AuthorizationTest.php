<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may call what. Run against a migrated, empty database:
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class AuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private array $eventA;
    private array $eventB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }

        $this->eventA = $this->makeEvent('Event A');
        $this->eventB = $this->makeEvent('Event B');
    }

    private function makeEvent(string $name): array
    {
        $eventId = DB::table('events')->insertGetId(['name' => $name, 'date' => now()->toDateString(), 'is_active' => 1, 'is_archived' => 0]);
        $specialtyId = DB::table('specialties')->insertGetId(['name' => "Spec {$name}"]);
        $esId = DB::table('event_specialties')->insertGetId(['event_id' => $eventId, 'specialty_id' => $specialtyId, 'opened_on' => QueueService::today()]);
        $dayId = DB::table('days')->insertGetId(['event_specialty_id' => $esId, 'day_date' => now()->toDateString()]);
        $hourId = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => '08:00:00']);
        $patientId = random_int(100000, 999999);
        $appointmentId = DB::table('appointments')->insertGetId([
            'event_id' => $eventId, 'user_id' => $this->user(User::ROLE_SUPER_ADMIN)->id,
            'event_specialty_id' => $esId, 'day_id' => $dayId, 'hour_id' => $hourId,
            'full_name' => "Patient {$name}", 'birthday' => '1990-01-01', 'sex' => '0',
            'phone' => '0555000000', 'phone2' => '0555000001', 'diseases' => 'secret diagnosis',
            'patient_id' => $patientId, 'status' => 'Pending',
            'medical_documents' => json_encode(['medical-documents/test.pdf']),
        ]);

        return compact('eventId', 'esId', 'dayId', 'hourId', 'appointmentId', 'patientId');
    }

    private function user(int $role, ?int $eventId = null): User
    {
        $user = User::create(['name' => 'u', 'email' => uniqid('u', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role, 'event_id' => $eventId])->save();

        return $user;
    }

    private function actingAsRole(int $role, ?int $eventId = null): User
    {
        $user = $this->user($role, $eventId);
        Sanctum::actingAs($user);

        return $user;
    }

    // ---------------------------------------------------------------- auth

    public function test_registration_cannot_choose_a_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'attacker', 'email' => 'attacker@test.local', 'password' => 'password123', 'role_id' => 1,
        ])->assertCreated();

        $this->assertSame(User::ROLE_SIMPLE_USER, (int) User::where('email', 'attacker@test.local')->value('role_id'));
    }

    public function test_login_does_not_reveal_whether_an_email_exists(): void
    {
        RateLimiter::clear('login');
        $known = $this->user(User::ROLE_SIMPLE_USER);

        $unknown = $this->postJson('/api/login', ['email' => 'nobody@test.local', 'password' => 'x']);
        $wrong = $this->postJson('/api/login', ['email' => $known->email, 'password' => 'x']);

        $unknown->assertStatus(422);
        $wrong->assertStatus(422);
        $this->assertSame($unknown->json(), $wrong->json());
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'brute@test.local', 'password' => "x{$i}"]);
        }
        $this->postJson('/api/login', ['email' => 'brute@test.local', 'password' => 'x'])->assertStatus(429);
    }

    // ------------------------------------------------- privilege escalation

    public function test_simple_user_cannot_take_over_accounts_or_change_roles(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $me = $this->actingAsRole(User::ROLE_SIMPLE_USER);

        $this->putJson("/api/users/{$admin->id}", ['password' => 'hacked123'])->assertForbidden();
        $this->postJson("/api/users/{$me->id}/assign", ['role_id' => 1])->assertForbidden();
        $this->postJson('/api/role/ChangeRole', ['user_id' => $me->id, 'role' => 1])->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();

        $this->assertSame(User::ROLE_SIMPLE_USER, (int) $me->fresh()->role_id);
    }

    public function test_simple_user_cannot_run_destructive_operations(): void
    {
        $this->actingAsRole(User::ROLE_SIMPLE_USER);

        $this->deleteJson("/api/events/{$this->eventA['eventId']}")->assertForbidden();
        $this->patchJson("/api/events/{$this->eventA['eventId']}/archive")->assertForbidden();
        $this->postJson("/api/events/{$this->eventA['eventId']}/reset-passwords", ['new_password' => 'xxxxxx'])->assertForbidden();
        $this->postJson('/api/appointment/delete-duplicates')->assertForbidden();
        $this->deleteJson('/api/test-appointments/delete-all')->assertForbidden();
        $this->postJson('/api/startVisitDay')->assertForbidden();
        $this->postJson('/api/waitinglist/deleteAppointmentsAndAdmins')->assertNotFound(); // removed
    }

    public function test_simple_user_cannot_read_patient_data(): void
    {
        $this->actingAsRole(User::ROLE_SIMPLE_USER);
        $a = $this->eventA;

        $this->getJson("/api/appointments/by-event?event_id={$a['eventId']}")->assertForbidden();
        $this->getJson("/api/events/{$a['eventId']}")->assertForbidden();
        $this->getJson("/api/archived-events/{$a['eventId']}/patients-list")->assertForbidden();
        $this->getJson("/api/appointments/{$a['appointmentId']}/documents/0")->assertForbidden();
    }

    public function test_super_admin_password_reset_revokes_tokens(): void
    {
        $target = $this->user(User::ROLE_RECIPIENT_ADMIN, $this->eventA['eventId']);
        $target->createToken('auth');
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $this->putJson("/api/users/{$target->id}", ['password' => 'newpassword1'])->assertOk();
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_super_admin_cannot_be_unassigned(): void
    {
        $other = $this->user(User::ROLE_SUPER_ADMIN);
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        $this->postJson("/api/users/{$other->id}/unassign")->assertForbidden();
        $this->assertSame(User::ROLE_SUPER_ADMIN, (int) $other->fresh()->role_id);
    }

    // ------------------------------------------------------- event scoping

    public function test_reception_works_on_own_event_only(): void
    {
        $this->actingAsRole(User::ROLE_RECIPIENT_ADMIN, $this->eventA['eventId']);
        $a = $this->eventA;
        $b = $this->eventB;

        $this->getJson("/api/appointments/by-event?event_id={$a['eventId']}")->assertOk();
        $this->getJson("/api/appointments/by-event?event_id={$b['eventId']}")->assertForbidden();

        $this->patchJson("/api/appointments/{$a['appointmentId']}/presence", ['status' => 'Present'])->assertOk();
        $this->patchJson("/api/appointments/{$b['appointmentId']}/presence", ['status' => 'Present'])->assertForbidden();
        $this->assertSame('Pending', Appointment::find($b['appointmentId'])->status);

        $this->putJson("/api/appointments/{$b['appointmentId']}", ['full_name' => 'x'])->assertForbidden();
        $this->getJson("/api/events/{$b['eventId']}")->assertForbidden();
        $this->getJson("/api/event-specialties/{$b['esId']}/days")->assertForbidden();
        $this->getJson("/api/days/{$b['dayId']}/hours")->assertForbidden();
    }

    public function test_reception_cannot_use_specialty_queue_or_admin_actions(): void
    {
        $this->actingAsRole(User::ROLE_RECIPIENT_ADMIN, $this->eventA['eventId']);

        $this->postJson("/api/waitinglist/Complete/{$this->eventA['appointmentId']}")->assertForbidden();
        $this->deleteJson("/api/appointments/{$this->eventA['appointmentId']}")->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_search_is_forced_to_own_event(): void
    {
        $this->actingAsRole(User::ROLE_RECIPIENT_ADMIN, $this->eventA['eventId']);

        $res = $this->getJson("/api/appointments/search?q=Patient&event_id={$this->eventB['eventId']}");
        $this->assertStringNotContainsString('Patient Event B', $res->getContent());
    }

    public function test_event_list_is_filtered_for_staff(): void
    {
        $this->actingAsRole(User::ROLE_CHECK_ADMIN, $this->eventA['eventId']);

        $ids = collect($this->getJson('/api/events')->assertOk()->json('events'))->pluck('id')->all();
        $this->assertSame([$this->eventA['eventId']], $ids);
    }

    public function test_check_admin_queue_actions_are_scoped(): void
    {
        $this->actingAsRole(User::ROLE_CHECK_ADMIN, $this->eventA['eventId']);

        $this->postJson("/api/waitinglist/Complete/{$this->eventB['appointmentId']}")->assertForbidden();
        $this->getJson("/api/waitinglist/GetWaitingListBySpeciality/{$this->eventB['eventId']}/1")->assertForbidden();
        $this->postJson('/api/appointment/addComment', ['appointment_id' => $this->eventB['appointmentId'], 'comment' => 'x'])->assertForbidden();
    }

    public function test_only_super_admin_opens_specialty_queues(): void
    {
        $esId = $this->eventA['esId'];
        foreach ([User::ROLE_CHECK_ADMIN, User::ROLE_RECIPIENT_ADMIN, User::ROLE_DOCTOR, User::ROLE_SPECIAL_ADMIN] as $role) {
            $this->actingAsRole($role, $this->eventA['eventId']);
            $this->patchJson("/api/event-specialties/{$esId}/toggle-open")->assertForbidden();
        }

        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $this->patchJson("/api/event-specialties/{$esId}/toggle-open")->assertOk()->assertJsonPath('is_open', false);
    }

    public function test_staff_without_event_has_no_access(): void
    {
        $this->actingAsRole(User::ROLE_RECIPIENT_ADMIN, null);

        $this->getJson("/api/appointments/by-event?event_id={$this->eventA['eventId']}")->assertForbidden();
        $this->getJson('/api/appointments/search?q=Patient')->assertForbidden();
    }

    public function test_special_case_only_touches_own_event(): void
    {
        // Same patient number in both events
        Appointment::whereKey($this->eventB['appointmentId'])->update(['patient_id' => $this->eventA['patientId']]);
        $this->actingAsRole(User::ROLE_SPECIAL_ADMIN, $this->eventA['eventId']);

        $this->postJson("/api/waitinglist/Special/{$this->eventA['appointmentId']}")->assertOk();
        $this->postJson("/api/waitinglist/Special/{$this->eventB['appointmentId']}")->assertForbidden();

        $this->assertSame('Present', Appointment::find($this->eventA['appointmentId'])->status);
        $this->assertSame('Pending', Appointment::find($this->eventB['appointmentId'])->status);
    }

    // ------------------------------------------------------ public surface

    public function test_public_event_endpoint_has_no_patient_data(): void
    {
        $res = $this->getJson("/api/public/events/{$this->eventA['eventId']}")->assertOk();

        $this->assertStringNotContainsString('secret diagnosis', $res->getContent());
        $this->assertStringNotContainsString('0555000000', $res->getContent());
    }

    public function test_staff_event_endpoint_has_no_patient_rows(): void
    {
        $this->actingAsRole(User::ROLE_RECIPIENT_ADMIN, $this->eventA['eventId']);

        $res = $this->getJson("/api/events/{$this->eventA['eventId']}")->assertOk();
        $this->assertStringNotContainsString('secret diagnosis', $res->getContent());
        $this->assertSame(1, $res->json('event.event_specialties.0.appointments_count'));
    }

    public function test_public_waiting_list_hides_archived_events(): void
    {
        DB::table('events')->where('id', $this->eventA['eventId'])->update(['is_archived' => 1]);

        $this->getJson("/api/public/waitinglist?event_id={$this->eventA['eventId']}")->assertNotFound();
    }

    public function test_booking_requires_an_active_event(): void
    {
        DB::table('events')->where('id', $this->eventA['eventId'])->update(['is_active' => 0]);

        $this->postJson("/api/appointment/{$this->eventA['eventId']}", [])->assertForbidden();
    }

    public function test_public_cannot_write_rules_or_content(): void
    {
        $this->postJson('/api/rules', ['rule' => 'x'])->assertUnauthorized();
        $this->deleteJson('/api/blogs/1')->assertUnauthorized();
        $this->postJson('/api/types', ['name' => 'x'])->assertUnauthorized();
        $this->deleteJson("/api/events/{$this->eventA['eventId']}/reports")->assertNotFound(); // removed
        $this->getJson('/api/volunteers')->assertUnauthorized();
        $this->getJson("/api/events/{$this->eventA['eventId']}/evaluations/statistics")->assertUnauthorized();
    }

    public function test_public_evaluation_cannot_set_admin_fields(): void
    {
        $res = $this->postJson('/api/evaluations', [
            'event_id' => $this->eventA['eventId'], 'appointment_id' => $this->eventB['appointmentId'],
            'age_range' => '18-35 سنة', 'gender' => 'ذكر', 'specialties' => ['x'],
            'overall_organization' => 5, 'medical_quality' => 5, 'staff_performance' => 5,
            'registration_ease' => 5, 'platform_organization' => 5,
            'suggested_specialties' => 'x', 'positive_points' => 'x', 'negative_points' => 'x',
            'suggestions' => 'x', 'would_recommend' => true,
            'is_processed' => 1, 'admin_notes' => 'injected',
        ])->assertCreated();

        $row = DB::table('evaluations')->find($res->json('evaluation.id'));
        $this->assertNotEquals('injected', $row->admin_notes);
        $this->assertNull($row->appointment_id); // appointment of another event is dropped
    }

    // --------------------------------------------------- medical documents

    public function test_documents_are_served_only_to_authorized_staff(): void
    {
        \Illuminate\Support\Facades\Storage::fake(Appointment::DOCUMENTS_DISK);
        \Illuminate\Support\Facades\Storage::disk(Appointment::DOCUMENTS_DISK)->put('medical-documents/test.pdf', '%PDF-1.4 test');

        $url = "/api/appointments/{$this->eventA['appointmentId']}/documents/0";
        $this->getJson($url)->assertUnauthorized();

        $this->actingAsRole(User::ROLE_RECIPIENT_ADMIN, $this->eventB['eventId']);
        $this->getJson($url)->assertForbidden();

        $this->actingAsRole(User::ROLE_RECIPIENT_ADMIN, $this->eventA['eventId']);
        $this->get($url)->assertOk();

        $urls = Appointment::find($this->eventA['appointmentId'])->medical_document_urls;
        $this->assertStringContainsString("/api/appointments/{$this->eventA['appointmentId']}/documents/0", $urls[0]);
    }

    // ------------------------------------------- legitimate access still works

    /**
     * Endpoints each role's pages call (from the frontend), on their own event.
     * They must not be rejected by authorization (other errors are fine here).
     */
    public function test_each_role_keeps_access_to_its_own_pages(): void
    {
        $a = $this->eventA;
        $byRole = [
            User::ROLE_RECIPIENT_ADMIN => [ // CheckPresence
                ['GET', "/api/events/{$a['eventId']}"],
                ['GET', "/api/appointments/by-event?event_id={$a['eventId']}"],
                ['GET', "/api/appointments/reminder-stats/{$a['eventId']}"],
                ['GET', "/api/appointments/evaluated-stats/{$a['eventId']}"],
                ['PATCH', "/api/appointments/{$a['appointmentId']}/presence", ['status' => 'Present']],
                ['POST', '/api/appointments/mark-multiple-reminded', ['patient_id' => $a['patientId'], 'event_id' => $a['eventId']]],
                ['POST', '/api/appointments/mark-multiple-evaluated', ['patient_id' => $a['patientId'], 'event_id' => $a['eventId']]],
                ['PUT', "/api/appointments/{$a['appointmentId']}", ['full_name' => 'Patient Event A']],
                ['GET', '/api/events'],
                ['GET', '/api/user'],
            ],
            User::ROLE_SPECIAL_ADMIN => [ // CheckPresence + special cases + big list + bulk add
                ['GET', "/api/appointments/by-event?event_id={$a['eventId']}"],
                ['GET', "/api/appointments/{$a['appointmentId']}/available-specialties"],
                ['POST', "/api/waitinglist/Special/{$a['appointmentId']}"],
                ['GET', "/api/events/{$a['eventId']}/specialties"],
                ['GET', "/api/event-specialties/{$a['esId']}/days"],
                ['GET', "/api/days/{$a['dayId']}/hours"],
                ['GET', "/api/check-slot-availability?event_specialty_id={$a['esId']}&day_id={$a['dayId']}&hour_id={$a['hourId']}"],
                ['POST', '/api/appointments/add-multiple-different', ['event_id' => $a['eventId'], 'appointments' => []]],
            ],
            User::ROLE_CHECK_ADMIN => [ // CheckPaitent
                ['GET', "/api/events/{$a['eventId']}"],
                ['GET', "/api/waitinglist/GetWaitingListBySpeciality/{$a['eventId']}/1"],
                ['GET', "/api/appointment/patient-appointments/{$a['patientId']}/{$a['eventId']}"],
                ['POST', "/api/waitinglist/Commingsoon/{$a['appointmentId']}", ['position' => 1]],
                ['POST', '/api/appointment/addComment', ['appointment_id' => $a['appointmentId'], 'comment' => 'ok']],
            ],
            User::ROLE_DOCTOR => [ // CheckPaitent (read + comments)
                ['GET', "/api/waitinglist/GetWaitingListBySpeciality/{$a['eventId']}/1"],
                ['GET', "/api/appointment/patient-appointments/{$a['patientId']}/{$a['eventId']}"],
                ['POST', '/api/appointment/addComment', ['appointment_id' => $a['appointmentId'], 'comment' => 'ok']],
            ],
        ];

        foreach ($byRole as $role => $calls) {
            $this->actingAsRole($role, $a['eventId']);
            foreach ($calls as $call) {
                [$method, $url] = $call;
                $status = $this->json($method, $url, $call[2] ?? [])->status();
                $this->assertNotContains($status, [401, 403], "role {$role} was refused {$method} {$url}");
            }
        }

        $this->assertSame('ok', Appointment::find($a['appointmentId'])->comment); // addComment really saves now
    }

    public function test_super_admin_reaches_admin_pages(): void
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $a = $this->eventA;

        foreach ([
            "/api/users", "/api/role", "/api/events", "/api/events/{$a['eventId']}",
            "/api/appointments/by-event?event_id={$a['eventId']}", "/api/events/{$a['eventId']}/admins/credentials",
            "/api/archived-events/{$a['eventId']}/specialty-stats", "/api/evaluations", "/api/volunteers", "/api/contacts",
            "/api/doctors", "/api/test-appointments/count", "/api/{$a['eventId']}/statistics",
        ] as $url) {
            $this->assertNotContains($this->getJson($url)->status(), [401, 403], "super admin was refused {$url}");
        }
    }
}
