<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of the staff space: every change request made by a staff
 * account (super admin included) and every staff login attempt: who, when,
 * what, on which event, and whether it succeeded. Read by the super admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('user_name')->nullable();   // kept when the account is deleted
            $t->unsignedTinyInteger('role_id')->nullable();
            $t->unsignedBigInteger('event_id')->nullable();
            $t->string('action', 120);              // Controller@method
            $t->string('method', 10);
            $t->string('path');
            $t->json('subject')->nullable();        // route ids, patient concerned
            $t->json('input')->nullable();          // request data, secrets removed
            $t->unsignedSmallInteger('status');
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index(['event_id', 'created_at']);
            $t->index(['user_id', 'created_at']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
    }
};
