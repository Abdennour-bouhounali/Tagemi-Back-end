<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreign('check_admin_id', 'fk_events_check_admin')
                ->references('id')->on('users')
                ->nullOnDelete();
            
            $table->foreign('special_admin_id', 'fk_events_special_admin')
                ->references('id')->on('users')
                ->nullOnDelete();
            
            $table->foreign('recipient_admin_id', 'fk_events_recipient_admin')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign('fk_events_check_admin');
            $table->dropForeign('fk_events_special_admin');
            $table->dropForeign('fk_events_recipient_admin');
        });
    }
};