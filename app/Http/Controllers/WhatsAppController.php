<?php

namespace App\Http\Controllers;

use App\Exceptions\RuleException;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppAudience;
use App\Services\WhatsApp\WhatsAppClient;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Super admin: WhatsApp messages to patients through the Cloud API.
 * A campaign = one approved template + the chosen patients. The page sends it
 * in small batches (POST …/send-next until nothing is left), so no queue
 * worker is needed and a closed page can resume where it stopped. Delivery
 * statuses come back through Meta's webhook.
 */
class WhatsAppController extends Controller
{
    private const BATCH = 20;
    private const BAD_PHONE = 'رقم الهاتف غير صالح لواتساب';

    public function __construct(private WhatsAppClient $client, private WhatsAppAudience $audience)
    {
    }

    /** GET /api/whatsapp/status: is it set up, which number sends, the variable fields. */
    public function status()
    {
        $phone = null;
        $error = null;
        if ($this->client->configured()) {
            try {
                $phone = $this->client->phoneNumber();
            } catch (Throwable $e) {
                $error = 'تعذر الاتصال بواتساب: تحقق من WHATSAPP_TOKEN و WHATSAPP_PHONE_NUMBER_ID';
                Log::warning('WhatsApp status failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'configured' => $this->client->configured(),
            'settings' => $this->client->settings(),
            'phone' => $phone,
            'error' => $error,
            'webhook_url' => url('/api/whatsapp/webhook'),
            'fields' => WhatsAppAudience::FIELDS,
        ]);
    }

    /** GET /api/whatsapp/templates */
    public function templates()
    {
        $this->assertConfigured();
        try {
            return response()->json(['templates' => $this->client->templates()]);
        } catch (Throwable $e) {
            Log::warning('WhatsApp templates failed', ['error' => $e->getMessage()]);
            throw new RuleException('تعذر جلب القوالب من واتساب: تحقق من WHATSAPP_BUSINESS_ACCOUNT_ID وصلاحيات الرمز', 502);
        }
    }

    /** POST /api/events/{eventId}/whatsapp/preview: how many patients, and three filled examples. */
    public function preview(Request $request, $eventId)
    {
        $event = Event::findOrFail((int) $eventId);
        $data = $this->validated($request);
        $template = $this->template($data);
        $patients = $this->audience->patients($event, $data['filters'], $template['name']);

        $withoutPhone = 0;
        $samples = [];
        foreach ($patients as $patientId => $appointments) {
            $phone = $this->audience->phoneOf($appointments);
            if (!$phone) {
                $withoutPhone++;
            }
            if (count($samples) < 3) {
                $values = $this->audience->values($event, $appointments);
                $samples[] = [
                    'patient_id' => (int) $patientId,
                    'full_name' => $appointments->first()->full_name,
                    'phone' => $phone,
                    'text' => $this->render($template, $data['variables'], $values),
                ];
            }
        }

        return response()->json([
            'count' => $patients->count(),
            'without_phone' => $withoutPhone,
            'samples' => $samples,
        ]);
    }

    /** POST /api/events/{eventId}/whatsapp/campaigns: freezes the recipients; the page then calls send-next. */
    public function store(Request $request, $eventId)
    {
        $event = Event::findOrFail((int) $eventId);
        $data = $this->validated($request) + $request->validate(['mark_reminded' => 'boolean']);
        $template = $this->template($data);
        $patients = $this->audience->patients($event, $data['filters'], $template['name']);
        if ($patients->isEmpty()) {
            throw new RuleException('لا يوجد زائر يطابق الاختيار', 422);
        }

        $campaign = DB::transaction(function () use ($event, $data, $template, $patients, $request) {
            $campaign = WhatsAppCampaign::create([
                'event_id' => $event->id,
                'user_id' => $request->user()->id,
                'template_name' => $template['name'],
                'template_language' => $template['language'],
                'template_body' => trim(($template['header']['text'] ?? '') . "\n" . $template['body']),
                'variables' => $data['variables'],
                'filters' => $data['filters'],
                'mark_reminded' => (bool) ($data['mark_reminded'] ?? false),
                'status' => 'sending',
                'total' => $patients->count(),
            ]);

            $now = now();
            $rows = [];
            foreach ($patients as $patientId => $appointments) {
                $phone = $this->audience->phoneOf($appointments);
                $values = $this->audience->values($event, $appointments);
                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'event_id' => $event->id,
                    'patient_id' => (int) $patientId,
                    'full_name' => $appointments->first()->full_name,
                    'phone' => $phone ?? mb_substr(preg_replace('/\D/', '', (string) $appointments->first()->phone), 0, 20),
                    'params' => json_encode([
                        'header' => $this->audience->parameters($data['variables']['header'], $values),
                        'body' => $this->audience->parameters($data['variables']['body'], $values),
                    ], JSON_UNESCAPED_UNICODE),
                    'status' => $phone ? 'pending' : 'failed',
                    'error' => $phone ? null : self::BAD_PHONE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                WhatsAppMessage::insert($chunk);
            }

            return $campaign;
        });

        return response()->json(['campaign' => $this->summary($campaign)], 201);
    }

    /**
     * POST /api/whatsapp/campaigns/{id}/send-next: sends the next batch.
     * Rows are claimed first ('sending'), so two open pages never send one twice.
     */
    public function sendNext($id)
    {
        $this->assertConfigured();
        $campaign = WhatsAppCampaign::findOrFail($id);

        // A page closed in the middle of a batch: its rows go back in line after 2 minutes
        WhatsAppMessage::where('campaign_id', $campaign->id)->where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(2))->update(['status' => 'pending']);

        $ids = WhatsAppMessage::where('campaign_id', $campaign->id)->where('status', 'pending')
            ->orderBy('id')->limit(self::BATCH)->pluck('id');
        $claimed = $ids->isEmpty() ? 0 : WhatsAppMessage::whereIn('id', $ids)->where('status', 'pending')
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if ($claimed) {
            foreach (WhatsAppMessage::whereIn('id', $ids)->where('status', 'sending')->get() as $message) {
                $this->sendOne($campaign, $message);
            }
        }

        $left = WhatsAppMessage::where('campaign_id', $campaign->id)->whereIn('status', ['pending', 'sending'])->count();
        if ($left === 0 && $campaign->status !== 'done') {
            $campaign->update(['status' => 'done']);
        }

        return response()->json(['campaign' => $this->summary($campaign->fresh()), 'left' => $left]);
    }

    /** POST /api/whatsapp/campaigns/{id}/retry: failed messages back in line (not the bad numbers). */
    public function retry($id)
    {
        $campaign = WhatsAppCampaign::findOrFail($id);
        $count = WhatsAppMessage::where('campaign_id', $campaign->id)->where('status', 'failed')
            ->where(fn ($q) => $q->whereNull('error')->orWhere('error', '!=', self::BAD_PHONE))
            ->update(['status' => 'pending', 'error' => null]);
        if ($count) {
            $campaign->update(['status' => 'sending']);
        }

        return response()->json(['campaign' => $this->summary($campaign->fresh()), 'requeued' => $count]);
    }

    /** GET /api/events/{eventId}/whatsapp/campaigns */
    public function index($eventId)
    {
        $campaigns = WhatsAppCampaign::with('user:id,name')->where('event_id', (int) $eventId)
            ->latest('id')->limit(100)->get()
            ->map(fn ($c) => $this->summary($c));

        return response()->json(['campaigns' => $campaigns]);
    }

    /** GET /api/whatsapp/campaigns/{id}: one campaign and the status of each message. */
    public function show($id)
    {
        $campaign = WhatsAppCampaign::with('user:id,name')->findOrFail($id);
        $messages = $campaign->messages()->orderBy('id')
            ->get(['id', 'patient_id', 'full_name', 'phone', 'status', 'error', 'sent_at', 'delivered_at', 'read_at']);

        return response()->json(['campaign' => $this->summary($campaign), 'messages' => $messages]);
    }

    /** GET /api/whatsapp/webhook: Meta checks the URL once, with our verify token. */
    public function verifyWebhook(Request $request)
    {
        $token = (string) config('services.whatsapp.verify_token');
        // PHP turns "hub.mode" into "hub_mode"
        if ($token !== '' && $request->query('hub_mode') === 'subscribe'
            && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** POST /api/whatsapp/webhook: delivery statuses (sent, delivered, read, failed), signed by Meta. */
    public function receiveWebhook(Request $request)
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');
        if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response('Invalid signature', 403);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $this->applyStatus((array) $status);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ helpers

    private function sendOne(WhatsAppCampaign $campaign, WhatsAppMessage $message): void
    {
        try {
            $waId = $this->client->sendTemplate(
                $message->phone,
                $campaign->template_name,
                $campaign->template_language,
                $message->params['body'] ?? [],
                $message->params['header'] ?? [],
            );
            $message->update([
                'wa_message_id' => $waId,
                'status' => 'sent',
                'sent_at' => now(),
                'error' => null,
            ]);
            if ($campaign->mark_reminded) {
                Appointment::where('event_id', $campaign->event_id)->where('patient_id', $message->patient_id)
                    ->update(['is_reminded' => true, 'reminded_at' => now()]);
            }
        } catch (WhatsAppException $e) {
            $message->update(['status' => 'failed', 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('WhatsApp send failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            $message->update(['status' => 'failed', 'error' => 'تعذر الاتصال بواتساب']);
        }
    }

    private function applyStatus(array $status): void
    {
        $new = $status['status'] ?? null;
        $message = isset($status['id']) ? WhatsAppMessage::where('wa_message_id', $status['id'])->first() : null;
        if (!$message || !isset(WhatsAppMessage::RANK[$new])) {
            return;
        }
        $at = isset($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : now();

        $fields = [];
        if ($new === 'failed') {
            // A failure after "sent" is final (e.g. the number has no WhatsApp)
            $error = $status['errors'][0] ?? [];
            $fields = [
                'status' => 'failed',
                'error' => trim(($error['title'] ?? 'فشل الإرسال') . (isset($error['code']) ? " ({$error['code']})" : '')),
            ];
        } elseif (WhatsAppMessage::RANK[$new] > (WhatsAppMessage::RANK[$message->status] ?? 0)) {
            // Statuses can arrive out of order: never go back (read stays read)
            $fields['status'] = $new;
        }
        if ($new === 'delivered' && !$message->delivered_at) {
            $fields['delivered_at'] = $at;
        }
        if ($new === 'read') {
            $fields['read_at'] = $message->read_at ?? $at;
            $fields['delivered_at'] = $message->delivered_at ?? $at;
        }
        if ($fields) {
            $message->update($fields);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'template_name' => 'required|string|max:512',
            'template_language' => 'required|string|max:15',
            'variables' => 'present|array',
            'variables.body' => 'array',
            'variables.header' => 'array',
            'variables.*.*.field' => 'nullable|string|in:' . implode(',', array_keys(WhatsAppAudience::FIELDS)),
            'variables.*.*.text' => 'nullable|string|max:1000',
            'filters' => 'present|array',
            'filters.specialties' => 'array',
            'filters.specialties.*' => 'integer',
            'filters.days' => 'array',
            'filters.days.*' => 'date_format:Y-m-d',
            'filters.statuses' => 'array',
            'filters.statuses.*' => 'in:' . implode(',', array_keys(WhatsAppAudience::STATUS_GROUPS)),
            'filters.reminded' => 'nullable|in:all,yes,no',
            'filters.patient_ids' => 'array',
            'filters.patient_ids.*' => 'integer',
            'filters.skip_already_sent' => 'boolean',
        ]);
        $data['variables'] = ['body' => $data['variables']['body'] ?? [], 'header' => $data['variables']['header'] ?? []];

        return $data;
    }

    /** The approved template from Meta; every variable must be filled. */
    private function template(array $data): array
    {
        $this->assertConfigured();
        try {
            $templates = $this->client->templates();
        } catch (Throwable $e) {
            throw new RuleException('تعذر جلب القوالب من واتساب', 502);
        }
        $template = collect($templates)->first(fn ($t) => $t['name'] === $data['template_name'] && $t['language'] === $data['template_language']);
        if (!$template || $template['status'] !== 'APPROVED') {
            throw new RuleException('القالب غير موجود أو لم يعتمده واتساب بعد', 422);
        }
        if (!$template['supported']) {
            throw new RuleException('هذا القالب فيه صورة أو ملف في العنوان، غير مدعوم هنا', 422);
        }
        foreach (['body', 'header'] as $part) {
            foreach ($template["{$part}_variables"] as $placeholder) {
                $v = $data['variables'][$part][$placeholder] ?? null;
                if (!$v || (empty($v['field']) && trim((string) ($v['text'] ?? '')) === '')) {
                    throw new RuleException('املأ المتغير {{' . $placeholder . '}}', 422);
                }
            }
        }

        return $template;
    }

    private function render(array $template, array $variables, array $values): string
    {
        $fill = function (string $text, array $vars) use ($values) {
            $params = $this->audience->parameters($vars, $values);

            return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', fn ($m) => $params[$m[1]] ?? $m[0], $text);
        };
        $header = ($template['header']['format'] ?? null) === 'TEXT' ? $fill($template['header']['text'] ?? '', $variables['header']) : '';

        return trim(($header ? "*{$header}*\n" : '') . $fill($template['body'], $variables['body']));
    }

    private function summary(WhatsAppCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'event_id' => $campaign->event_id,
            'template_name' => $campaign->template_name,
            'template_language' => $campaign->template_language,
            'template_body' => $campaign->template_body,
            'filters' => $campaign->filters,
            'mark_reminded' => $campaign->mark_reminded,
            'status' => $campaign->status,
            'total' => $campaign->total,
            'counts' => $campaign->counts(),
            'user' => $campaign->user?->name,
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }

    private function assertConfigured(): void
    {
        if (!$this->client->configured()) {
            throw new RuleException('واتساب غير مربوط بعد: أضف WHATSAPP_TOKEN و WHATSAPP_PHONE_NUMBER_ID و WHATSAPP_BUSINESS_ACCOUNT_ID في إعدادات الخادم', 422);
        }
    }
}
