<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    public const STATUSES = ['pending', 'sent', 'delivered', 'read', 'failed'];
    /** A webhook status never moves a message backwards (they can arrive out of order). */
    public const RANK = ['pending' => 0, 'failed' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'campaign_id', 'event_id', 'patient_id', 'full_name', 'phone', 'params',
        'status', 'wa_message_id', 'error', 'sent_at', 'delivered_at', 'read_at',
    ];

    protected $casts = [
        'params' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'campaign_id');
    }
}
