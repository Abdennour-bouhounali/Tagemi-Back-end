<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PATCH /api/users/{id}/password: only the super admin changes a password.
 * Run against a migrated, empty database:
 *   DB_DATABASE=tagemi_security_test php artisan test
 */
class UserPasswordTest extends TestCase
{
    use DatabaseTransactions;

    private const NEW = ['password' => 'newpassword1', 'password_confirmation' => 'newpassword1'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (User::ROLES as $name => $id) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
    }

    private function user(int $role): User
    {
        $user = User::create(['name' => 'old latin name', 'email' => uniqid('u', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role])->save();

        return $user;
    }

    public function test_super_admin_changes_a_password_and_the_account_is_signed_out(): void
    {
        $target = $this->user(User::ROLE_RECIPIENT_ADMIN);
        $target->createToken('auth');
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $this->patchJson("/api/users/{$target->id}/password", self::NEW)->assertOk();

        $this->assertTrue(Hash::check('newpassword1', $target->fresh()->password));
        $this->assertSame(0, $target->tokens()->count());
        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'newpassword1'])->assertSuccessful();
    }

    public function test_every_other_role_is_refused_even_on_their_own_account(): void
    {
        $roles = array_diff(User::ROLES, [User::ROLE_SUPER_ADMIN]);
        $this->assertNotEmpty($roles);

        foreach ($roles as $role) {
            $me = $this->user($role);
            Sanctum::actingAs($me);

            $this->patchJson("/api/users/{$me->id}/password", self::NEW)->assertForbidden();
            $this->assertTrue(Hash::check('password123', $me->fresh()->password), "role {$role}");
        }

        $this->app['auth']->forgetGuards();
        $target = $this->user(User::ROLE_SIMPLE_USER);
        $this->patchJson("/api/users/{$target->id}/password", self::NEW)->assertUnauthorized();
    }

    public function test_the_new_password_must_be_long_enough_and_confirmed(): void
    {
        $target = $this->user(User::ROLE_CHECK_ADMIN);
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));
        $url = "/api/users/{$target->id}/password";

        $this->patchJson($url, [])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->patchJson($url, ['password' => 'short12', 'password_confirmation' => 'short12'])->assertStatus(422);
        $this->patchJson($url, ['password' => 'newpassword1', 'password_confirmation' => 'different1'])->assertStatus(422);
        $this->patchJson($url, ['password' => 'newpassword1'])->assertStatus(422);

        $this->assertTrue(Hash::check('password123', $target->fresh()->password));
    }

    public function test_super_admin_keeps_the_current_session_when_changing_their_own_password(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $current = $admin->createToken('this-device');
        $admin->createToken('other-device');

        $this->withToken($current->plainTextToken)
            ->patchJson("/api/users/{$admin->id}/password", self::NEW)
            ->assertOk();

        $this->assertSame([$current->accessToken->id], $admin->tokens()->pluck('id')->all());
    }

    public function test_general_edit_no_longer_touches_the_password(): void
    {
        $target = $this->user(User::ROLE_CHECK_ADMIN);
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $this->putJson("/api/users/{$target->id}", ['password' => 'newpassword1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('password123', $target->fresh()->password));

        $this->putJson("/api/users/{$target->id}", ['phone' => '0555000000'])->assertOk();
    }

    public function test_the_change_is_audited_without_the_password(): void
    {
        $target = $this->user(User::ROLE_CHECK_ADMIN);
        Sanctum::actingAs($this->user(User::ROLE_SUPER_ADMIN));

        $this->patchJson("/api/users/{$target->id}/password", self::NEW)->assertOk();

        $row = AdminAction::where('action', 'UserController@changePassword')->latest('id')->firstOrFail();
        $this->assertSame('غيّر كلمة مرور مستخدم', $row->label());
        $this->assertSame((string) $target->id, (string) $row->subject['id']);
        $this->assertSame('***', $row->input['password']);
        $this->assertSame('***', $row->input['password_confirmation']);
    }
}
