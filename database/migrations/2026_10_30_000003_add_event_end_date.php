<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Last day of an event (`date` is the first). Existing events get the last of
 * their specialties' days, or their start date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $t) {
            $t->date('end_date')->nullable()->after('date');
        });

        DB::table('events')->orderBy('id')->each(function ($event) {
            $last = DB::table('days')
                ->join('event_specialties', 'event_specialties.id', '=', 'days.event_specialty_id')
                ->where('event_specialties.event_id', $event->id)
                ->max('days.day_date');
            $start = substr((string) $event->date, 0, 10);
            $last = $last ? substr((string) $last, 0, 10) : $start;
            DB::table('events')->where('id', $event->id)->update(['end_date' => max($start, $last)]);
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('end_date'));
    }
};
