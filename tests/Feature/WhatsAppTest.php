<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\QueueService;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** WhatsApp Cloud API campaigns; Meta's API is faked (no real message leaves). */
class WhatsAppTest extends TestCase
{
    use DatabaseTransactions;

    private int $eventId;
    private array $es = [];
    private int $nextPatient = 880000;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'SuperAdmin', 2 => 'SimpleUser', 3 => 'SpecialAdmin', 4 => 'CheckAdmin', 5 => 'RecipientAdmin', 6 => 'Doctor', 7 => 'ContentAdmin', 8 => 'PatientManager', 9 => 'Organizer'] as $id => $name) {
            DB::table('roles')->updateOrInsert(['id' => $id], ['name' => $name]);
        }
        DB::table('users')->updateOrInsert(['id' => 1], ['name' => 'admin', 'email' => 'admin1@test.local', 'password' => bcrypt('x'), 'role_id' => 1]);

        config([
            'services.whatsapp.token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1111',
            'services.whatsapp.business_account_id' => '2222',
            'services.whatsapp.app_secret' => 'app-secret',
            'services.whatsapp.verify_token' => 'verify-me',
            'services.whatsapp.api_version' => 'v23.0',
            'services.frontend_url' => 'https://tagemi-foundation.org',
        ]);

        $this->eventId = DB::table('events')->insertGetId([
            'name' => 'قافلة الاختبار', 'date' => QueueService::today(), 'is_active' => 1, 'is_archived' => 0, 'nbr_of_allowed_specialties' => 3,
        ]);
        foreach (['Eyes', 'Heart'] as $name) {
            $specialtyId = DB::table('specialties')->insertGetId(['name' => $name . uniqid()]);
            $esId = DB::table('event_specialties')->insertGetId(['event_id' => $this->eventId, 'specialty_id' => $specialtyId]);
            $dayId = DB::table('days')->insertGetId(['event_specialty_id' => $esId, 'day_date' => Carbon::now(QueueService::TIMEZONE)->addDay()->toDateString()]);
            $hourId = DB::table('hours')->insertGetId(['day_id' => $dayId, 'time' => '09:00:00', 'max_allowed' => 50]);
            $this->es[$esId] = [$dayId, $hourId];
        }

        Sanctum::actingAs($this->staff(User::ROLE_SUPER_ADMIN));
    }

    private function staff(int $role): User
    {
        $user = User::create(['name' => 'staff', 'email' => uniqid('s', true) . '@test.local', 'password' => 'password123']);
        $user->forceFill(['role_id' => $role, 'event_id' => $this->eventId])->save();

        return $user;
    }

    private function patient(array $esIds, string $phone = '0555123456', string $name = 'محمد بن علي'): int
    {
        $patientId = ++$this->nextPatient;
        foreach ($esIds as $i => $esId) {
            [$dayId, $hourId] = $this->es[$esId];
            Appointment::create([
                'user_id' => 1, 'event_id' => $this->eventId, 'event_specialty_id' => $esId, 'day_id' => $dayId, 'hour_id' => $hourId,
                'full_name' => $name . " {$patientId}", 'birthday' => '1980-01-01', 'sex' => '0', 'phone' => $phone, 'phone2' => '',
                'patient_id' => $patientId, 'specialty_order' => $i === 0 ? 1 : 0, 'status' => 'Pending',
            ]);
        }

        return $patientId;
    }

    private function specialtyName(int $esId): string
    {
        return (string) DB::table('event_specialties')->join('specialties', 'specialties.id', '=', 'event_specialties.specialty_id')
            ->where('event_specialties.id', $esId)->value('specialties.name');
    }

    /** Meta: an approved template "rappel" (ar) with {{1}} name and {{2}} appointments; sends answer with an id. */
    private function fakeMeta(array $sendResponses = []): void
    {
        $seq = Http::sequence();
        foreach ($sendResponses as [$status, $body]) {
            $seq->push($body, $status);
        }
        $seq->whenEmpty(Http::response(['messages' => [['id' => 'wamid.' . uniqid()]]], 200));

        Http::fake([
            'graph.facebook.com/v23.0/2222/message_templates*' => Http::response(['data' => [[
                'id' => 't1', 'name' => 'rappel', 'language' => 'ar', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'السلام عليكم {{1}}، مواعيدكم: {{2}}']],
            ], [
                'id' => 't2', 'name' => 'pending_one', 'language' => 'ar', 'status' => 'PENDING', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'نص']],
            ]]]),
            'graph.facebook.com/v23.0/1111/messages' => $seq,
            'graph.facebook.com/v23.0/1111*' => Http::response(['display_phone_number' => '+213 555 00 00 00', 'verified_name' => 'Tagemi']),
        ]);
    }

    private function payload(array $filters, bool $markReminded = false): array
    {
        return [
            'template_name' => 'rappel',
            'template_language' => 'ar',
            'variables' => ['body' => ['1' => ['field' => 'full_name'], '2' => ['field' => 'appointments']]],
            'filters' => $filters,
            'mark_reminded' => $markReminded,
        ];
    }

    private function sendAll(int $campaignId): array
    {
        for ($i = 0; $i < 20; $i++) {
            $res = $this->postJson("/api/whatsapp/campaigns/{$campaignId}/send-next")->assertOk()->json();
            if ($res['left'] === 0) {
                return $res;
            }
        }
        $this->fail('campaign never finished');
    }

    public function test_status_and_templates_need_the_settings(): void
    {
        config(['services.whatsapp.token' => null]);
        $this->getJson('/api/whatsapp/status')->assertOk()->assertJson(['configured' => false, 'settings' => ['token' => false, 'app_secret' => true]]);
        $this->getJson('/api/whatsapp/templates')->assertStatus(422);
    }

    public function test_templates_show_their_variables(): void
    {
        $this->fakeMeta();
        $templates = $this->getJson('/api/whatsapp/templates')->assertOk()->json('templates');
        $this->assertSame(['1', '2'], $templates[0]['body_variables']);
        $this->assertSame('PENDING', $templates[1]['status']);
    }

    public function test_preview_counts_patients_of_a_specialty_and_fills_the_text(): void
    {
        $this->fakeMeta();
        [$eyes, $heart] = array_keys($this->es);
        $a = $this->patient([$eyes, $heart]);
        $this->patient([$heart]);
        $this->patient([$eyes], '12'); // no usable phone

        $res = $this->postJson("/api/events/{$this->eventId}/whatsapp/preview", $this->payload(['specialties' => [$eyes]]))->assertOk();
        $this->assertSame(2, $res->json('count'));
        $this->assertSame(1, $res->json('without_phone'));
        $sample = $res->json('samples.0');
        $this->assertSame($a, $sample['patient_id']);
        $this->assertSame('213555123456', $sample['phone']);
        $this->assertStringContainsString("السلام عليكم محمد بن علي {$a}، مواعيدكم: " . $this->specialtyName($eyes), $sample['text']);
        $this->assertStringContainsString(' • ' . $this->specialtyName($heart), $sample['text']); // one line: no new line in a variable
    }

    public function test_campaign_sends_each_patient_once_and_marks_reminded(): void
    {
        $this->fakeMeta();
        [$eyes, $heart] = array_keys($this->es);
        $a = $this->patient([$eyes, $heart], '+213 555 12 34 56');
        $b = $this->patient([$eyes], '0661 00 00 00');
        $bad = $this->patient([$eyes], '0123');

        $campaign = $this->postJson("/api/events/{$this->eventId}/whatsapp/campaigns", $this->payload(['specialties' => [$eyes]], true))
            ->assertCreated()->json('campaign');
        $this->assertSame(3, $campaign['total']);
        $this->assertSame(1, $campaign['counts']['failed']); // the bad number, before any send

        $done = $this->sendAll($campaign['id']);
        $this->assertSame('done', $done['campaign']['status']);
        $this->assertSame(['pending' => 0, 'sent' => 2, 'delivered' => 0, 'read' => 0, 'failed' => 1], $done['campaign']['counts']);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/1111/messages')
            && $r['to'] === '213555123456'
            && $r['template']['name'] === 'rappel'
            && $r['template']['language']['code'] === 'ar'
            && $r['template']['components'][0]['parameters'][0]['text'] === "محمد بن علي {$a}"
            && $r->hasHeader('Authorization', 'Bearer test-token'));
        // One template lookup (when the campaign is created) + one message per patient with a good number
        Http::assertSentCount(3);
        $this->assertTrue((bool) Appointment::where('patient_id', $a)->first()->is_reminded);
        $this->assertTrue((bool) Appointment::where('patient_id', $b)->first()->is_reminded);
        $this->assertFalse((bool) Appointment::where('patient_id', $bad)->first()->is_reminded);
    }

    public function test_meta_error_is_shown_and_can_be_retried(): void
    {
        $this->fakeMeta([[400, ['error' => ['code' => 131026, 'message' => 'Message undeliverable']]]]);
        [$eyes] = array_keys($this->es);
        $p = $this->patient([$eyes]);

        $campaign = $this->postJson("/api/events/{$this->eventId}/whatsapp/campaigns", $this->payload(['patient_ids' => [$p]]))->json('campaign');
        $this->sendAll($campaign['id']);
        $message = WhatsAppMessage::where('campaign_id', $campaign['id'])->first();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('لا يستعمل واتساب', $message->error);

        $this->postJson("/api/whatsapp/campaigns/{$campaign['id']}/retry")->assertOk()->assertJson(['requeued' => 1]);
        $this->sendAll($campaign['id']);
        $this->assertSame('sent', $message->fresh()->status);
    }

    public function test_skip_patients_who_already_received_the_template(): void
    {
        $this->fakeMeta();
        [$eyes] = array_keys($this->es);
        $a = $this->patient([$eyes]);
        $first = $this->postJson("/api/events/{$this->eventId}/whatsapp/campaigns", $this->payload(['patient_ids' => [$a]]))->json('campaign');
        $this->sendAll($first['id']);
        $b = $this->patient([$eyes]);

        $res = $this->postJson("/api/events/{$this->eventId}/whatsapp/preview", $this->payload(['specialties' => [$eyes], 'skip_already_sent' => true]))->assertOk();
        $this->assertSame(1, $res->json('count'));
        $this->assertSame($b, $res->json('samples.0.patient_id'));
    }

    public function test_unapproved_template_or_missing_variable_is_refused(): void
    {
        $this->fakeMeta();
        [$eyes] = array_keys($this->es);
        $this->patient([$eyes]);

        $this->postJson("/api/events/{$this->eventId}/whatsapp/preview", ['template_name' => 'pending_one', 'template_language' => 'ar', 'variables' => [], 'filters' => []])
            ->assertStatus(422);
        $payload = $this->payload([]);
        unset($payload['variables']['body']['2']);
        $this->postJson("/api/events/{$this->eventId}/whatsapp/preview", $payload)->assertStatus(422)->assertJsonFragment(['message' => 'املأ المتغير {{2}}']);
    }

    public function test_webhook_checks_the_token_and_the_signature_and_never_goes_back(): void
    {
        $this->fakeMeta();
        [$eyes] = array_keys($this->es);
        $p = $this->patient([$eyes]);
        $campaign = $this->postJson("/api/events/{$this->eventId}/whatsapp/campaigns", $this->payload(['patient_ids' => [$p]]))->json('campaign');
        $this->sendAll($campaign['id']);
        $waId = WhatsAppMessage::where('campaign_id', $campaign['id'])->value('wa_message_id');

        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();

        $post = function (string $status, bool $signed) use ($waId) {
            $body = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => $waId, 'status' => $status, 'timestamp' => (string) time()]]]]]]]]);

            return $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signed ? 'sha256=' . hash_hmac('sha256', $body, 'app-secret') : 'sha256=bad',
            ], $body);
        };

        $post('read', false)->assertForbidden();
        $post('read', true)->assertOk();
        $post('delivered', true)->assertOk(); // late "delivered" after "read": stays read
        $message = WhatsAppMessage::where('wa_message_id', $waId)->first();
        $this->assertSame('read', $message->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertNotNull($message->read_at);
    }

    public function test_only_the_super_admin_sends(): void
    {
        foreach ([User::ROLE_RECIPIENT_ADMIN, User::ROLE_SPECIAL_ADMIN, User::ROLE_ORGANIZER] as $role) {
            Sanctum::actingAs($this->staff($role));
            $this->getJson('/api/whatsapp/status')->assertForbidden();
            $this->postJson("/api/events/{$this->eventId}/whatsapp/campaigns", $this->payload([]))->assertForbidden();
        }
    }

    public function test_phone_numbers_become_international(): void
    {
        $this->assertSame('213555123456', WhatsAppClient::normalizePhone('0555 12 34 56'));
        $this->assertSame('213555123456', WhatsAppClient::normalizePhone('555123456'));
        $this->assertSame('213555123456', WhatsAppClient::normalizePhone('+213 555-12-34-56'));
        $this->assertSame('213555123456', WhatsAppClient::normalizePhone('00213555123456'));
        $this->assertNull(WhatsAppClient::normalizePhone('0212345678')); // landline
        $this->assertNull(WhatsAppClient::normalizePhone('12'));
        $this->assertSame('a • b c', WhatsAppClient::cleanParameter("a\nb     c"));
    }
}
