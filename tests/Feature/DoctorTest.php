<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Doctors registry and their login accounts. Run against a migrated, empty database:
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class DoctorTest extends TestCase
{
    use DatabaseTransactions;

    private int $specialtyId;

    // Unique per test: doctors' phones are unique and the test database is shared
    private string $phone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phone = '06' . random_int(10000000, 99999999);

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 4 => 'CheckAdmin', 6 => 'Doctor'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        $this->specialtyId = DB::table('specialties')->insertGetId(['name' => 'طب العيون']);
    }

    private function user(int $role): User
    {
        $user = User::create(['name' => 'u', 'email' => uniqid('u', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role])->save();

        return $user;
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'first_name' => 'أمين',
            'family_name' => 'بن علي',
            'phone' => chunk_split($this->phone, 2, ' '), // spaces are stripped
            'wilaya_code' => '16',
            'specialty_id' => $this->specialtyId,
            'sector' => 'both',
        ];
    }

    public function test_only_super_admin_manages_doctors(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_CHECK_ADMIN));

        $this->getJson('/api/doctors')->assertForbidden();
        $this->postJson('/api/doctors', $this->payload())->assertForbidden();
    }

    public function test_create_makes_a_doctor_login_shown_once(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $res = $this->postJson('/api/doctors', $this->payload())->assertCreated();

        $doctor = Doctor::findOrFail($res->json('doctor.id'));
        $this->assertSame($this->phone, $doctor->phone);
        $this->assertSame('أمين بن علي', $res->json('doctor.full_name'));
        $this->assertSame("doctor.{$doctor->id}@tagemi.local", $res->json('credentials.email'));

        $user = $doctor->user;
        $this->assertSame(User::ROLE_DOCTOR, (int) $user->role_id);
        $this->assertNull($user->event_id);
        $this->assertTrue(Hash::check($res->json('credentials.password'), $user->password));
        $this->assertArrayNotHasKey('password', $res->json('doctor.user'));

        // The new account can log in
        $this->postJson('/api/login', ['email' => $user->email, 'password' => $res->json('credentials.password')])->assertCreated();
    }

    public function test_validation(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $this->postJson('/api/doctors', $this->payload())->assertCreated();

        $this->postJson('/api/doctors', $this->payload(['phone' => $this->phone]))->assertJsonValidationErrors('phone');
        $this->postJson('/api/doctors', $this->payload(['phone' => '12345']))->assertJsonValidationErrors('phone');
        $this->postJson('/api/doctors', $this->payload(['wilaya_code' => '59']))->assertJsonValidationErrors('wilaya_code');
        $this->postJson('/api/doctors', $this->payload(['sector' => 'other']))->assertJsonValidationErrors('sector');
        $this->postJson('/api/doctors', $this->payload(['first_name' => '']))->assertJsonValidationErrors('first_name');
    }

    public function test_reset_password_replaces_it_and_signs_out(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $created = $this->postJson('/api/doctors', $this->payload())->json();
        $doctor = Doctor::findOrFail($created['doctor']['id']);
        $doctor->user->createToken('auth');

        $reset = $this->postJson("/api/doctors/{$doctor->id}/reset-password")->assertOk();

        $user = $doctor->user->fresh();
        $this->assertSame($created['credentials']['email'], $reset->json('credentials.email'));
        $this->assertTrue(Hash::check($reset->json('credentials.password'), $user->password));
        $this->assertFalse(Hash::check($created['credentials']['password'], $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_deactivated_doctor_cannot_log_in(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $created = $this->postJson('/api/doctors', $this->payload())->json();

        $this->patchJson("/api/doctors/{$created['doctor']['id']}/active")->assertOk()->assertJsonPath('doctor.is_active', false);

        $this->postJson('/api/login', $created['credentials'])->assertForbidden();
    }

    public function test_update_syncs_account_name(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $id = $this->postJson('/api/doctors', $this->payload())->json('doctor.id');

        // Same phone is allowed for the doctor itself
        $this->putJson("/api/doctors/{$id}", $this->payload(['first_name' => 'سليم']))->assertOk();

        $this->assertSame('سليم بن علي', Doctor::find($id)->user->name);
    }

    public function test_delete_refused_once_used_in_an_event(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $id = $this->postJson('/api/doctors', $this->payload())->json('doctor.id');
        $userId = Doctor::find($id)->user_id;

        $eventId = DB::table('events')->insertGetId(['name' => 'e', 'date' => now()->toDateString()]);
        $esId = DB::table('event_specialties')->insertGetId(['event_id' => $eventId, 'specialty_id' => $this->specialtyId]);
        DB::table('event_specialty_doctors')->insert(['event_specialty_id' => $esId, 'doctor_id' => $id]);

        $this->deleteJson("/api/doctors/{$id}")->assertStatus(409);

        DB::table('event_specialty_doctors')->where('doctor_id', $id)->delete();
        $this->deleteJson("/api/doctors/{$id}")->assertOk();
        $this->assertNull(Doctor::find($id));
        $this->assertNull(User::find($userId));
    }

    public function test_specialty_with_doctors_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $this->postJson('/api/doctors', $this->payload())->assertCreated();

        $this->deleteJson("/api/specialty/{$this->specialtyId}")->assertStatus(409);
    }
}
