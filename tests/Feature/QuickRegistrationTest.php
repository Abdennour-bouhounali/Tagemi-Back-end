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
 * Presence desk «إضافة تسجيلات» (POST /events/{e}/quick-registrations) and the
 * reception accounts the super admin allows past the event's specialty limit.
 */
class QuickRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;
    /** event_specialty id => [todayHourId, tomorrowHourId] */
    private array $es = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager', 9 => 'Organizer'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);

        $this->eventId = DB::table('events')->insertGetId([
            'name' => 'Quick', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 2,
        ]);

        foreach (['Eyes', 'Heart', 'Teeth'] as $name) {
            $specialtyId = DB::table('specialties')->insertGetId(['name' => $name . uniqid()]);
            $esId = DB::table('event_specialties')->insertGetId(['event_id' => $this->eventId, 'specialty_id' => $specialtyId]);
            // Yesterday (past: never used), today 08:00 with one place, tomorrow 09:00
            DB::table('hours')->insert(['day_id' => DB::table('days')->insertGetId([
                'event_specialty_id' => $esId, 'day_date' => Carbon::now(QueueService::TIMEZONE)->subDay()->toDateString(),
            ]), 'time' => '08:00:00', 'max_allowed' => 50]);
            foreach ([[0, '08:00:00', 1], [1, '09:00:00', 50]] as [$offset, $time, $max]) {
                $dayId = DB::table('days')->insertGetId([
                    'event_specialty_id' => $esId, 'day_date' => Carbon::now(QueueService::TIMEZONE)->addDays($offset)->toDateString(),
                ]);
                $this->es[$esId][] = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => $time, 'max_allowed' => $max]);
            }
        }
    }

    private function staff(int $role, bool $tagged = false): User
    {
        $user = User::create(['name' => 'staff', 'email' => uniqid('s', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role, 'event_id' => $this->eventId, 'can_exceed_specialty_limit' => $tagged])->save();

        return $user;
    }

    private function row(array $specialties, array $overrides = []): array
    {
        return array_merge([
            'key' => uniqid(), 'name' => 'محمد', 'lastName' => 'بن علي', 'birthday' => '1980-05-04', 'sex' => '0',
            'phone' => '0555 12 34 56', 'state' => 'غرداية', 'city' => 'القرارة', 'specialties' => $specialties,
        ], $overrides);
    }

    private function register(array $rows)
    {
        return $this->postJson("/api/events/{$this->eventId}/quick-registrations", ['rows' => $rows])->assertOk();
    }

    public function test_reception_registers_several_rows_in_the_hour_of_registration(): void
    {
        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));
        [$a, $b] = array_keys($this->es);
        // Today has 08:00 (one place, so full after the first row) and 10:00
        $todayDay = DB::table('hours')->where('id', $this->es[$a][0])->value('day_id');
        $ten = DB::table('hours')->insertGetId(['day_id' => $todayDay, 'time' => '10:00:00', 'max_allowed' => 50]);

        Carbon::setTestNow(Carbon::now(QueueService::TIMEZONE)->setTime(9, 30));
        $res = $this->register([$this->row([$a, $b]), $this->row([$a], ['name' => 'فاطمة', 'sex' => '1'])]);

        $first = $res->json('results.0');
        $this->assertTrue($first['ok']);
        $this->assertCount(2, $first['appointments']);
        $rows = Appointment::where('patient_id', $first['patient_id'])->orderBy('id')->get();
        $this->assertSame('محمد بن علي', $rows[0]->full_name);
        $this->assertSame(1, (int) $rows[0]->specialty_order);
        $this->assertFalse($rows[0]->beyond_limit);
        // 09:30: the 08:00 hour (the one that started), not 10:00, not tomorrow
        $this->assertSame($this->es[$a][0], (int) $rows[0]->hour_id);
        $this->assertSame($this->es[$b][0], (int) $rows[1]->hour_id);

        // Same hour for the second person even though it is now full: they are at the desk
        $second = $res->json('results.1');
        $this->assertTrue($second['ok']);
        $this->assertNotSame($first['patient_id'], $second['patient_id']);
        $this->assertSame($this->es[$a][0], (int) Appointment::where('patient_id', $second['patient_id'])->value('hour_id'));

        // Before the day starts: the first hour; after 10:00: the 10:00 hour
        Carbon::setTestNow(Carbon::now(QueueService::TIMEZONE)->setTime(6, 0));
        $early = $this->register([$this->row([$a], ['name' => 'علي'])])->json('results.0.patient_id');
        $this->assertSame($this->es[$a][0], (int) Appointment::where('patient_id', $early)->value('hour_id'));
        Carbon::setTestNow(Carbon::now(QueueService::TIMEZONE)->setTime(11, 15));
        $late = $this->register([$this->row([$a], ['name' => 'عمر'])])->json('results.0.patient_id');
        $this->assertSame($ten, (int) Appointment::where('patient_id', $late)->value('hour_id'));
        Carbon::setTestNow();
    }

    public function test_without_a_day_today_the_next_free_hour_is_taken(): void
    {
        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));
        [$a] = array_keys($this->es);
        DB::table('days')->where('id', DB::table('hours')->where('id', $this->es[$a][0])->value('day_id'))->delete();

        $p = $this->register([$this->row([$a])])->json('results.0.patient_id');
        $this->assertSame($this->es[$a][1], (int) Appointment::where('patient_id', $p)->value('hour_id'));
    }

    public function test_same_name_birthday_and_phone_is_rejected(): void
    {
        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));
        [$a, $b] = array_keys($this->es);
        $first = $this->register([$this->row([$a])])->json('results.0');

        // Same person, phone typed another way and name spelled with أ/ا: rejected, nothing saved
        $dup = $this->register([$this->row([$b], ['name' => 'محمّد', 'phone' => '+213555123456'])])->json('results.0');
        $this->assertFalse($dup['ok']);
        $this->assertSame($first['patient_id'], $dup['patient_id']);
        $this->assertSame(1, Appointment::where('event_id', $this->eventId)->count());

        // Another phone: a different person, but one name + birthday holds a specialty once (database rule)
        $sameSpecialty = $this->register([$this->row([$a], ['phone' => '0666000000'])])->json('results.0');
        $this->assertFalse($sameSpecialty['ok']);
        $this->assertSame($first['patient_id'], $sameSpecialty['patient_id']);
        $this->assertTrue($this->register([$this->row([$b], ['phone' => '0666000000'])])->json('results.0.ok'));
        // Another birthday: a different person
        $this->assertTrue($this->register([$this->row([$a], ['birthday' => '1981-05-04'])])->json('results.0.ok'));
    }

    public function test_one_bad_row_does_not_stop_the_others(): void
    {
        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));
        [$a] = array_keys($this->es);

        $res = $this->register([$this->row([$a], ['birthday' => '']), $this->row([$a], ['name' => 'علي'])]);
        $this->assertFalse($res->json('results.0.ok'));
        $this->assertSame('تاريخ الميلاد مطلوب', $res->json('results.0.message'));
        $this->assertTrue($res->json('results.1.ok'));
    }

    public function test_specialty_limit_applies_unless_the_account_is_tagged(): void
    {
        [$a, $b, $c] = array_keys($this->es);

        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));
        $refused = $this->register([$this->row([$a, $b, $c])])->json('results.0');
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('2', $refused['message']);

        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN, true));
        $ok = $this->register([$this->row([$a, $b, $c])])->json('results.0');
        $this->assertTrue($ok['ok']);
        $rows = Appointment::where('patient_id', $ok['patient_id'])->orderBy('id')->get();
        $this->assertSame([false, false, true], $rows->pluck('beyond_limit')->all());

        // The third one is not counted: the patient can still be checked in
        $this->postJson("/api/events/{$this->eventId}/check-in", ['patient_id' => $ok['patient_id']])->assertOk();
    }

    public function test_add_specialty_only_for_tagged_reception_and_past_the_limit(): void
    {
        [$a, $b, $c] = array_keys($this->es);
        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN));
        $p = $this->register([$this->row([$a, $b])])->json('results.0');
        $source = Appointment::where('patient_id', $p['patient_id'])->first();
        $tomorrowDay = DB::table('hours')->where('id', $this->es[$c][1])->value('day_id');
        $payload = ['event_specialty_id' => $c, 'day_id' => $tomorrowDay, 'hour_id' => $this->es[$c][1]];

        $this->postJson("/api/appointments/{$source->id}/add-specialty", $payload)->assertForbidden();

        Sanctum::actingAs($this->staff(User::ROLE_RECIPIENT_ADMIN, true));
        $res = $this->postJson("/api/appointments/{$source->id}/add-specialty", $payload)->assertCreated();
        $this->assertTrue(Appointment::find($res->json('appointment.id'))->beyond_limit);
    }

    public function test_super_admin_tags_only_reception_accounts(): void
    {
        $reception = $this->staff(User::ROLE_RECIPIENT_ADMIN);
        $check = $this->staff(User::ROLE_CHECK_ADMIN);

        Sanctum::actingAs($reception);
        $this->patchJson("/api/users/{$reception->id}/specialty-limit", ['allowed' => true])->assertForbidden();

        Sanctum::actingAs($this->staff(User::ROLE_SUPER_ADMIN));
        $this->patchJson("/api/users/{$reception->id}/specialty-limit", ['allowed' => true])->assertOk();
        $this->assertTrue($reception->fresh()->can_exceed_specialty_limit);
        $this->patchJson("/api/users/{$check->id}/specialty-limit", ['allowed' => true])->assertStatus(422);
    }

    public function test_organizer_and_check_admin_cannot_register(): void
    {
        [$a] = array_keys($this->es);
        foreach ([User::ROLE_ORGANIZER, User::ROLE_CHECK_ADMIN] as $role) {
            Sanctum::actingAs($this->staff($role));
            $this->postJson("/api/events/{$this->eventId}/quick-registrations", ['rows' => [$this->row([$a])]])->assertForbidden();
        }
    }
}
