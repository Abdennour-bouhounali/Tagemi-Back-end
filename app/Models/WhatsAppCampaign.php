<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppCampaign extends Model
{
    protected $table = 'whatsapp_campaigns';

    protected $fillable = [
        'event_id', 'user_id', 'template_name', 'template_language', 'template_body',
        'variables', 'filters', 'mark_reminded', 'status', 'total',
    ];

    protected $casts = [
        'variables' => 'array',
        'filters' => 'array',
        'mark_reminded' => 'boolean',
    ];

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'campaign_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** {pending, sent, delivered, read, failed} counts. */
    public function counts(): array
    {
        $counts = $this->messages()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $result = collect(WhatsAppMessage::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
        $result['pending'] += (int) ($counts['sending'] ?? 0); // being sent right now

        return $result;
    }
}
