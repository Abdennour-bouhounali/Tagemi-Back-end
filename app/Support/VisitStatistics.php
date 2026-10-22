<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The single definition of how appointment statuses are counted. Every
 * statistics endpoint uses it, so all screens show the same numbers.
 * The frontend mirrors it in src/utils/visitStatus.js.
 */
class VisitStatistics
{
    public const GROUPS = [
        'pending' => ['Pending'],                 // booked, not arrived
        'waiting' => ['Waiting'],                 // arrived, on hold for this specialty
        'in_queue' => ['Present', 'Commingsoon'], // in this specialty's queue
        'dilatation' => ['DilatationSent', 'Dilatation'], // sent to / in the dilatation room
        'completed' => ['Completed'],
    ];

    public static function groupOf(?string $status): string
    {
        foreach (self::GROUPS as $group => $statuses) {
            if (in_array($status, $statuses, true)) {
                return $group;
            }
        }

        return 'pending'; // legacy values (Absent, Delay ...) were never real visits
    }

    /**
     * Final outcome of an appointment once the event is over:
     * Completed (examined), Pending (never came), Waiting (came, not examined).
     */
    public static function outcomeOf(?string $status): string
    {
        return match (self::groupOf($status)) {
            'completed' => 'Completed',
            'pending' => 'Pending',
            default => 'Waiting',
        };
    }

    /**
     * Appointment counts per group, plus patient counts (a patient with
     * three specialties is one patient and three appointments).
     *
     * @param Collection $appointments items with status and patient_id
     */
    public static function summarize(Collection $appointments): array
    {
        $counts = array_fill_keys(array_keys(self::GROUPS), 0);
        foreach ($appointments as $appointment) {
            $counts[self::groupOf($appointment->status)]++;
        }

        $byPatient = $appointments->groupBy('patient_id');

        return $counts + [
            'total' => $appointments->count(),
            'patients_total' => $byPatient->count(),
            'patients_arrived' => $byPatient->filter(fn ($rows) => $rows->contains(fn ($a) => $a->status !== 'Pending'))->count(),
            'patients_finished' => $byPatient->filter(fn ($rows) => $rows->every(fn ($a) => $a->status === 'Completed'))->count(),
        ];
    }
}
