<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API (Meta Graph API). Business-initiated messages must use a
 * template approved in WhatsApp Manager; free text is only allowed within 24 h
 * of the patient's last message, so this client only sends templates.
 */
class WhatsAppClient
{
    public function configured(): bool
    {
        return (bool) (config('services.whatsapp.token') && config('services.whatsapp.phone_number_id') && config('services.whatsapp.business_account_id'));
    }

    /** Which settings are present (never their values). */
    public function settings(): array
    {
        return collect(['token', 'phone_number_id', 'business_account_id', 'app_secret', 'verify_token'])
            ->mapWithKeys(fn ($k) => [$k => (bool) config("services.whatsapp.$k")])
            ->all();
    }

    /** The sending number as Meta sees it: display number, approved name, quality. */
    public function phoneNumber(): array
    {
        return $this->http()
            ->get($this->url(config('services.whatsapp.phone_number_id')), [
                'fields' => 'display_phone_number,verified_name,quality_rating,name_status',
            ])
            ->throw()
            ->json();
    }

    /**
     * Templates of the business account, with their text and the variables
     * ({{1}} or {{name}}) of the body and of a text header.
     */
    public function templates(): array
    {
        $templates = [];
        $url = $this->url(config('services.whatsapp.business_account_id') . '/message_templates');
        $query = ['fields' => 'name,language,status,category,components,parameter_format', 'limit' => 100];
        for ($page = 0; $url && $page < 10; $page++) {
            $res = $this->http()->get($url, $query)->throw()->json();
            foreach ($res['data'] ?? [] as $t) {
                $templates[] = self::describe($t);
            }
            $url = $res['paging']['next'] ?? null;
            $query = []; // the next link carries its own query
        }

        return $templates;
    }

    /**
     * Sends one template message. $body / $header: [placeholder => text] in
     * the template's order. Returns Meta's message id.
     *
     * @throws WhatsAppException
     */
    public function sendTemplate(string $to, string $name, string $language, array $body, array $header = []): string
    {
        $components = [];
        if ($header) {
            $components[] = ['type' => 'header', 'parameters' => self::parameters($header)];
        }
        if ($body) {
            $components[] = ['type' => 'body', 'parameters' => self::parameters($body)];
        }

        $res = $this->http()->post($this->url(config('services.whatsapp.phone_number_id') . '/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => array_filter([
                'name' => $name,
                'language' => ['code' => $language],
                'components' => $components ?: null,
            ]),
        ]);

        $id = $res->json('messages.0.id');
        if ($res->failed() || !$id) {
            throw new WhatsAppException(self::errorText($res->json('error') ?? []), $res->status());
        }

        return $id;
    }

    /**
     * Template as the UI needs it. Positional ({{1}}) or named ({{first_name}})
     * placeholders, in order of first appearance.
     */
    public static function describe(array $t): array
    {
        $body = '';
        $header = null;
        foreach ($t['components'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'BODY') {
                $body = $c['text'] ?? '';
            }
            if (($c['type'] ?? '') === 'HEADER') {
                $header = ['format' => $c['format'] ?? 'TEXT', 'text' => $c['text'] ?? null];
            }
        }

        return [
            'id' => $t['id'] ?? null,
            'name' => $t['name'],
            'language' => $t['language'],
            'status' => $t['status'] ?? null,
            'category' => $t['category'] ?? null,
            'body' => $body,
            'body_variables' => self::placeholders($body),
            'header' => $header,
            'header_variables' => ($header['format'] ?? null) === 'TEXT' ? self::placeholders($header['text'] ?? '') : [],
            // Image / video / document headers need a file: not supported here
            'supported' => !$header || ($header['format'] ?? 'TEXT') === 'TEXT',
        ];
    }

    /** "{{1}} … {{2}}" → ['1', '2']; "{{name}}" → ['name']. */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Phone typed at registration → international digits for WhatsApp.
     * 0555 12 34 56 / 555123456 / +213 555… / 00213 555… → 213555123456.
     * Null when it cannot be a mobile number.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        $cc = (string) config('services.whatsapp.country_code', '213');
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = $cc . substr($digits, 1);
        } elseif (strlen($digits) === 9 && !str_starts_with($digits, '0')) {
            $digits = $cc . $digits;
        }
        // Algeria: 213 + 9 digits starting with 5, 6 or 7 (mobiles)
        if ($cc === '213' && str_starts_with($digits, '213')) {
            return preg_match('/^213[567]\d{8}$/', $digits) ? $digits : null;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * A template parameter may not contain new lines, tabs or more than four
     * spaces in a row, and may not be empty (Meta rejects the message).
     */
    public static function cleanParameter(?string $text): string
    {
        $text = preg_replace('/[\r\n\t]+/u', ' • ', (string) $text);
        $text = trim(preg_replace('/ {2,}/u', ' ', $text), " •");

        return $text === '' ? '-' : mb_substr($text, 0, 1000);
    }

    private static function parameters(array $values): array
    {
        $named = collect(array_keys($values))->contains(fn ($k) => !ctype_digit((string) $k));

        return collect($values)->map(fn ($text, $key) => array_filter([
            'type' => 'text',
            'parameter_name' => $named ? (string) $key : null,
            'text' => self::cleanParameter($text),
        ]))->values()->all();
    }

    private static function errorText(array $error): string
    {
        $details = $error['error_data']['details'] ?? null;
        $code = $error['code'] ?? null;
        $known = [
            131026 => 'الرقم لا يستعمل واتساب أو لا يستقبل الرسائل',
            131047 => 'مرت أكثر من 24 ساعة على آخر رسالة من الزائر',
            131049 => 'رفض واتساب الرسالة للحفاظ على جودة التواصل، أعد المحاولة لاحقاً',
            131050 => 'الزائر أوقف استقبال الرسائل التسويقية',
            132000 => 'عدد المتغيرات لا يطابق القالب',
            132001 => 'القالب غير موجود أو غير معتمد بهذه اللغة',
            131056 => 'رسائل كثيرة لنفس الرقم في وقت قصير',
            130429 => 'تجاوزنا عدد الرسائل المسموح في الثانية، أعد المحاولة',
            131042 => 'مشكلة في وسيلة الدفع لحساب واتساب للأعمال',
            190 => 'رمز الوصول (WHATSAPP_TOKEN) منتهٍ أو غير صالح',
        ];

        return trim(($known[$code] ?? ($error['message'] ?? 'خطأ غير معروف من واتساب'))
            . ($code ? " ({$code})" : '') . ($details && !isset($known[$code]) ? ": {$details}" : ''));
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('services.whatsapp.token'))
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.whatsapp.graph_url', 'https://graph.facebook.com'), '/') . '/'
            . config('services.whatsapp.api_version', 'v23.0') . '/' . ltrim($path, '/');
    }
}
