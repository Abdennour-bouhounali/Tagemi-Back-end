<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use App\Models\Appointment;
use App\Models\AppointmentArchive;
use Illuminate\Support\Facades\DB;

class ArchiveAppointments extends Command
{
    protected $signature = 'appointments:archive';
    protected $description = 'Move appointments of ended events to the archive table';

    public function handle()
    {
        $this->info("Starting appointment archiving process...");

        // Get all ended events
        $endedEvents = Event::where('is_current', false)->get();

        foreach ($endedEvents as $event) {
            DB::transaction(function () use ($event) {
                $appointments = Appointment::where('event_id', $event->id)->get();

                if ($appointments->count() > 0) {
                    // Copy data to archive
                    foreach ($appointments as $a) {
                        AppointmentArchive::create($a->toArray());
                    }

                    // Delete from main table
                    Appointment::where('event_id', $event->id)->delete();

                    $this->info("Archived {$appointments->count()} appointments for event: {$event->name}");
                }
            });
        }

        $this->info("✅ Archiving complete!");
        return 0;
    }
}

