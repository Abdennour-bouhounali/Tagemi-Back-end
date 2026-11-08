<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «تقييم المتطوعين»: one evaluation per event, anonymous volunteers from a QR
 * code, super admin only for the questions, session and results.
 *   DB_DATABASE=tagemi_security_test php artisan test --filter=VolunteerEvaluationTest
 */
class VolunteerEvaluationTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 4 => 'CheckAdmin', 9 => 'Organizer'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        $this->eventId = DB::table('events')->insertGetId(['name' => 'Caravan', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0]);
    }

    private function actingAsRole(int $role): User
    {
        $user = User::create(['name' => 'u', 'email' => uniqid('u', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role])->save();
        Sanctum::actingAs($user);

        return $user;
    }

    private function createEvaluation(): array
    {
        $this->actingAsRole(User::ROLE_SUPER_ADMIN);

        return $this->getJson("/api/volunteer-eval/{$this->eventId}")->assertOk()->json('evaluation');
    }

    private function asVolunteer(string $token): static
    {
        // Back to a guest: the public routes must not need an account
        $this->app['auth']->forgetGuards();

        return $this->withHeader('X-Eval-Token', $token);
    }

    public function test_opening_creates_one_evaluation_per_event_from_the_default_file(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/public/volunteer-eval/{$this->eventId}")->assertNotFound();

        $evaluation = $this->createEvaluation();
        $file = json_decode(file_get_contents(resource_path('data/volunteer-evaluation-default.json')), true);

        $this->assertSame($file['title'], $evaluation['title']);
        $this->assertEquals($file['roles'], $evaluation['roles']);
        $this->assertEquals($file['axes'], $evaluation['axes']);

        $this->assertCount(12, $evaluation['roles']);
        $this->assertCount(12, $evaluation['axes']);
        $this->assertSame(74, collect($evaluation['axes'])->sum(fn ($a) => count($a['qs'])));
        $this->assertSame('eval', $evaluation['session']['phase']);

        $this->getJson("/api/volunteer-eval/{$this->eventId}")->assertOk()->assertJsonPath('evaluation.id', $evaluation['id']);
        $this->assertSame(1, DB::table('volunteer_evaluations')->where('event_id', $this->eventId)->count());
    }

    public function test_only_the_super_admin_manages_the_evaluation(): void
    {
        foreach ([User::ROLE_ORGANIZER, User::ROLE_CHECK_ADMIN] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/volunteer-eval/{$this->eventId}")->assertForbidden();
            $this->getJson("/api/volunteer-eval/{$this->eventId}/results")->assertForbidden();
        }
    }

    public function test_a_volunteer_joins_anonymously_answers_and_resumes(): void
    {
        $evaluation = $this->createEvaluation();
        $this->app['auth']->forgetGuards();

        $config = $this->getJson("/api/public/volunteer-eval/{$this->eventId}")->assertOk()->json();
        $this->assertSame('Caravan', $config['event']['name']);

        $this->postJson("/api/public/volunteer-eval/{$this->eventId}/join", ['role_id' => 'nope'])->assertUnprocessable();
        $token = $this->postJson("/api/public/volunteer-eval/{$this->eventId}/join", ['role_id' => 'r_rec'])
            ->assertCreated()->json('token');

        $axis = $evaluation['axes'][2]; // b3, reception
        $q = $axis['qs'][0]['id'];
        $this->asVolunteer($token)->putJson("/api/public/volunteer-eval/{$this->eventId}/me/axes/{$axis['id']}", [
            'status' => 'done',
            'overall' => 4,
            'ratings' => [$q => 5, 'b1q1' => 3, $axis['qs'][1]['id'] => 0],
            'positives' => [['text' => ' سريع ', 'reason' => 'عدد كاف', 'recommendation' => ''], ['text' => '', 'reason' => '', 'recommendation' => '']],
            'negatives' => [],
        ])->assertOk();

        $mine = $this->asVolunteer($token)->getJson("/api/public/volunteer-eval/{$this->eventId}/me")->assertOk()->json('participant');
        $this->assertSame('r_rec', $mine['role_id']);
        $saved = $mine['answers'][$axis['id']];
        $this->assertSame('done', $saved['status']);
        // A question of another axis is dropped; «لا أعرف» (0) is kept
        $this->assertSame([$q => 5, $axis['qs'][1]['id'] => 0], $saved['ratings']);
        $this->assertSame([['text' => 'سريع', 'reason' => 'عدد كاف', 'recommendation' => '']], $saved['positives']);

        $this->asVolunteer('wrong-token')->getJson("/api/public/volunteer-eval/{$this->eventId}/me")->assertUnauthorized();
    }

    public function test_results_average_without_dont_know_and_count_progress(): void
    {
        $evaluation = $this->createEvaluation();
        $axis = $evaluation['axes'][0];
        $q = $axis['qs'][0]['id'];
        $this->app['auth']->forgetGuards();

        $tokens = collect(['r_sup', 'r_rec', 'r_doc'])->map(fn ($role) => $this->postJson(
            "/api/public/volunteer-eval/{$this->eventId}/join", ['role_id' => $role]
        )->json('token'));

        $save = fn ($token, $body) => $this->asVolunteer($token)
            ->putJson("/api/public/volunteer-eval/{$this->eventId}/me/axes/{$axis['id']}", $body)->assertOk();
        $save($tokens[0], ['status' => 'done', 'overall' => 5, 'ratings' => [$q => 4], 'negatives' => [['text' => 'تأخر']]]);
        $save($tokens[1], ['status' => 'done', 'overall' => 0, 'ratings' => [$q => 0]]);
        $save($tokens[2], ['status' => 'skipped']);

        $this->actingAsRole(User::ROLE_SUPER_ADMIN);
        $results = $this->getJson("/api/volunteer-eval/{$this->eventId}/results")->assertOk()->json();

        $this->assertSame(3, $results['stats']['participants']);
        $first = $results['axes'][0];
        $this->assertSame(2, $first['done']);
        $this->assertSame(1, $first['skipped']);
        $this->assertEquals(1, $first['progress']);
        $this->assertEquals(5, $first['average']);
        $this->assertEquals(4, $first['questions'][0]['average']);
        $this->assertSame(1, $first['questions'][0]['count']);
        $this->assertSame('تأخر', $first['negatives'][0]['text']);
        $this->assertEqualsWithDelta(1 / 12, $results['stats']['progress'], 0.001);
        $this->assertArrayNotHasKey('token_hash', $results['participants'][0]);
    }

    public function test_a_closed_evaluation_refuses_answers(): void
    {
        $this->createEvaluation();
        $this->putJson("/api/volunteer-eval/{$this->eventId}/session", ['is_open' => false])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/public/volunteer-eval/{$this->eventId}/join", ['role_id' => 'r_rec'])->assertStatus(423);
        $this->getJson("/api/public/volunteer-eval/{$this->eventId}")->assertOk()->assertJsonPath('is_open', false);
    }

    public function test_the_timer_runs_on_the_server_clock(): void
    {
        $this->createEvaluation();

        $session = $this->putJson("/api/volunteer-eval/{$this->eventId}/session", ['timer' => 'start', 'duration_min' => 20])->assertOk()->json();
        $this->assertEqualsWithDelta($session['now'] + 20 * 60000, $session['session']['ends_at'], 1000);

        $added = $this->putJson("/api/volunteer-eval/{$this->eventId}/session", ['timer' => 'add5'])->json('session.ends_at');
        $this->assertSame($session['session']['ends_at'] + 5 * 60000, $added);

        $this->putJson("/api/volunteer-eval/{$this->eventId}/session", ['timer' => 'stop', 'phase' => 'results'])
            ->assertJsonPath('session.ends_at', 0)
            ->assertJsonPath('session.phase', 'results');
    }

    public function test_config_keeps_question_ids_and_drops_unknown_roles(): void
    {
        $evaluation = $this->createEvaluation();
        $axes = $evaluation['axes'];
        $axes[0]['roles'] = ['r_sup', 'ghost'];
        $axes[0]['qs'][0]['t'] = 'سؤال معدّل';

        $saved = $this->putJson("/api/volunteer-eval/{$this->eventId}/config", [
            'title' => 'تقييم', 'roles' => $evaluation['roles'], 'axes' => $axes,
        ])->assertOk()->json('evaluation');

        $this->assertSame(['r_sup'], $saved['axes'][0]['roles']);
        $this->assertSame('سؤال معدّل', $saved['axes'][0]['qs'][0]['t']);
        $this->assertSame($evaluation['axes'][0]['qs'][0]['id'], $saved['axes'][0]['qs'][0]['id']);

        $axes[1]['qs'][0]['id'] = $axes[0]['qs'][0]['id']; // duplicate question id
        $this->putJson("/api/volunteer-eval/{$this->eventId}/config", [
            'title' => 'تقييم', 'roles' => $evaluation['roles'], 'axes' => $axes,
        ])->assertUnprocessable();
    }
}
