<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New-event forms saved as the super admin types, so an unfinished event can
 * be resumed later (closed tab, laptop off). `data` is the form's state as the
 * front end keeps it; the draft is deleted when the event is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_drafts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('name')->nullable();
            $t->longText('data');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_drafts');
    }
};
