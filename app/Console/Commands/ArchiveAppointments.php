<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use App\Models\Appointment;
use App\Models\AppointmentArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveAppointments extends Command
{
    protected $signature = 'appointments:archive {--event-id= : Specific event ID to archive} {--auto : Auto-archive ended events}';
    protected $description = 'Move appointments of ended events to the archive table';

    public function handle()
    {
        $this->info("Starting appointment archiving process...");

        try {
            $eventId = $this->option('event-id');
            $auto = $this->option('auto');

            if ($eventId) {
                $event = Event::find($eventId);
                if (!$event) {
                    $this->error("Event with ID {$eventId} not found!");
                    return 1;
                }
                $this->archiveEvent($event);
            } elseif ($auto) {
                $this->autoArchiveEndedEvents();
            } else {
                $this->interactiveArchive();
            }

            $this->info("✅ Archiving complete!");
            return 0;
        } catch (\Exception $e) {
            $this->error("Error during archiving: " . $e->getMessage());
            Log::error('Appointment archiving failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    private function archiveEvent(Event $event)
    {
        if ($event->is_archived) {
            $this->warn("Event '{$event->name}' is already archived. Skipping...");
            return;
        }

        DB::transaction(function () use ($event) {
            $appointments = Appointment::where('event_id', $event->id)->get();

            if ($appointments->count() > 0) {
                $this->info("Found {$appointments->count()} appointments for event: {$event->name}");
                
                $progressBar = $this->output->createProgressBar($appointments->count());
                $progressBar->start();

                foreach ($appointments as $appointment) {
                    $archiveData = $appointment->toArray();
                    $archiveData['id'] = $appointment->id; // Keep same ID
                    $archiveData['archived_at'] = now();
                    $archiveData['archived_reason'] = 'Event ended';
                    
                    AppointmentArchive::insert($archiveData);
                    $progressBar->advance();
                }

                $progressBar->finish();
                $this->newLine();

                Appointment::where('event_id', $event->id)->delete();

                $this->info("✓ Archived {$appointments->count()} appointments for event: {$event->name}");
            } else {
                $this->warn("No appointments found for event: {$event->name}");
            }

            // Mark event as archived
            $event->update([
                'is_archived' => true,
                'is_active' => false
            ]);

            Log::info('Event archived successfully', [
                'event_id' => $event->id,
                'event_name' => $event->name,
                'appointments_count' => $appointments->count()
            ]);
        });
    }

    private function autoArchiveEndedEvents()
    {
        $endedEvents = Event::where('is_active', false)
                           ->where('is_archived', false)
                           ->where('date', '<', now())
                           ->get();

        if ($endedEvents->isEmpty()) {
            $this->info("No ended events found to archive.");
            return;
        }

        $this->info("Found {$endedEvents->count()} ended events to archive.");

        foreach ($endedEvents as $event) {
            $this->archiveEvent($event);
        }
    }

    private function interactiveArchive()
    {
        $endedEvents = Event::where('date', '<', now())
                           ->where('is_archived', false)
                           ->get();

        if ($endedEvents->isEmpty()) {
            $this->info("No ended events found to archive.");
            return;
        }

        $this->info("Found {$endedEvents->count()} ended events:");
        
        $headers = ['ID', 'Name', 'Date', 'Appointments Count'];
        $rows = $endedEvents->map(function ($event) {
            return [
                $event->id,
                $event->name,
                $event->date->format('Y-m-d'),
                $event->appointments()->count()
            ];
        });

        $this->table($headers, $rows);

        if ($this->confirm('Do you want to archive all these events?')) {
            foreach ($endedEvents as $event) {
                $this->archiveEvent($event);
            }
        } else {
            $this->info('Archiving cancelled.');
        }
    }
}