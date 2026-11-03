<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Cloud API sends: a campaign = one approved template sent to the
 * patients chosen by the super admin (by specialty, day, status, or one by
 * one); one message row per patient with its delivery status (from Meta's
 * webhook: sent → delivered → read, or failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // api: sent by the Cloud API; manual: typed text, opened one by one in WhatsApp by the admin
            $table->string('channel', 10)->default('api');
            $table->string('template_name');
            $table->string('template_language', 15);
            $table->text('template_body')->nullable();   // the approved text, kept for the history
            $table->json('variables');                   // {{n}} → patient field or fixed text
            $table->json('filters');                     // who was chosen
            $table->boolean('mark_reminded')->default(false);
            $table->string('status', 20)->default('sending'); // sending | done
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('full_name')->nullable();
            $table->string('phone', 20);                 // international digits, e.g. 213555123456
            $table->json('params');                      // values of {{1}}, {{2}}… for this patient
            $table->string('status', 20)->default('pending'); // pending | sent | delivered | read | failed
            $table->string('wa_message_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'patient_id']);
            $table->index(['event_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_campaigns');
    }
};
