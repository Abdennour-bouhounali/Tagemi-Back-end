<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every account has an Arabic first and family name. Accounts without them
 * (French name, first name only) are flagged needs_name_update and fix it themselves.
 */
class UserNameTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 6 => 'Doctor', 9 => 'Organizer'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
    }

    private function account(string $name, int $role = User::ROLE_SIMPLE_USER, array $parts = []): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid('u').'@test.local', 'password' => 'password123'] + $parts);
        $user->forceFill(['role_id' => $role])->save();

        return $user->fresh();
    }

    public function test_registration_requires_arabic_first_and_family_name(): void
    {
        $base = ['email' => 'new@test.local', 'password' => 'password123'];

        $this->postJson('/api/register', $base + ['first_name' => 'محمد'])->assertStatus(422)->assertJsonValidationErrors('family_name');
        $this->postJson('/api/register', $base + ['first_name' => 'Mohamed', 'family_name' => 'بن علي'])->assertStatus(422)->assertJsonValidationErrors('first_name');
        $this->postJson('/api/register', $base + ['name' => 'محمد بن علي'])->assertStatus(422);

        $res = $this->postJson('/api/register', $base + ['first_name' => ' عبد الله ', 'family_name' => 'بن  علي'])->assertCreated();
        $this->assertSame('عبد الله بن علي', $res->json('user.name'));
        $this->assertFalse($res->json('user.needs_name_update'));
    }

    public function test_old_accounts_are_flagged_and_fix_their_name(): void
    {
        $french = $this->account('Mohamed Ali');
        $onlyFirst = $this->account('محمد', User::ROLE_ORGANIZER);
        $fine = $this->account('محمد بن علي', User::ROLE_SIMPLE_USER, ['first_name' => 'محمد', 'family_name' => 'بن علي']);

        $this->assertTrue($french->needs_name_update);
        $this->assertTrue($onlyFirst->needs_name_update);
        $this->assertFalse($fine->needs_name_update);

        Sanctum::actingAs($french);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('needs_name_update', true);
        $this->putJson('/api/user/name', ['first_name' => 'Mohamed', 'family_name' => 'Ali'])->assertStatus(422);
        $this->putJson('/api/user/name', ['first_name' => 'محمد', 'family_name' => 'علي'])->assertOk();
        $this->getJson('/api/user')->assertJsonPath('needs_name_update', false)->assertJsonPath('name', 'محمد علي');

        // The read-only organizer may still set their own name
        Sanctum::actingAs($onlyFirst);
        $this->putJson('/api/user/name', ['first_name' => 'محمد', 'family_name' => 'قاسمي'])->assertOk();
    }

    public function test_doctor_registry_follows_the_doctor_account_name(): void
    {
        $specialtyId = DB::table('specialties')->insertGetId(['name' => 'Spec']);
        $user = $this->account('Karim Haddad', User::ROLE_DOCTOR);
        $doctor = Doctor::create(['first_name' => 'Karim', 'family_name' => 'Haddad', 'sex' => 0, 'specialty_id' => $specialtyId, 'is_active' => true]);
        $doctor->user()->associate($user)->save();

        Sanctum::actingAs($user);
        $this->putJson('/api/user/name', ['first_name' => 'كريم', 'family_name' => 'حداد'])->assertOk();
        $this->assertSame('كريم حداد', $doctor->fresh()->full_name);
    }

    public function test_split_of_existing_arabic_names(): void
    {
        $this->assertSame(['عبد الرحمن', 'بن علي'], User::splitArabicName('عبد الرحمن بن علي'));
        $this->assertSame(['محمد', 'علي'], User::splitArabicName(' محمد  علي '));
        $this->assertSame([null, null], User::splitArabicName('محمد'));
        $this->assertSame([null, null], User::splitArabicName('Mohamed Ali'));
        $this->assertSame([null, null], User::splitArabicName('محمد 2'));
    }
}
