<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\EventSpecialty;
use App\Services\QueueService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fingerprint of what an event's staff screens show. Every open screen polls it,
 * so polling reads cached(); anything that must not be stale reads compute().
 */
class LiveVersion
{
    /** Up to app.live_cache_seconds old: all devices of an event share one computation. */
    public static function cached(int $eventId): array
    {
        return Cache::remember("live-version:{$eventId}", (int) config('app.live_cache_seconds'), fn () => self::compute($eventId));
    }

    /** @return array{version: string, parts: array<string, string>} */
    public static function compute(int $eventId): array
    {
        // updated_at has one-second precision: x tells apart two changes in the same second
        $parts = Appointment::where('event_id', $eventId)
            ->selectRaw("event_specialty_id, COUNT(*) as c, MAX(updated_at) as u, SUM(orderList) as o,
                BIT_XOR(CRC32(CONCAT_WS('|', id, status, orderList, is_priority, is_special, is_reminded, is_evaluated,
                    day_id, hour_id, doctor_id, patient_id, specialty_order, beyond_limit, full_name, birthday, sex,
                    state, city, phone, phone2, diseases, LENGTH(medical_documents)))) as x")
            ->groupBy('event_specialty_id')
            ->get()
            ->mapWithKeys(fn ($row) => ['es_' . $row->event_specialty_id => md5("{$row->c}|{$row->u}|{$row->o}|{$row->x}")])
            ->all();

        $doctors = DB::table('doctor_attendances')
            ->join('days', 'days.id', '=', 'doctor_attendances.day_id')
            ->join('event_specialties', 'event_specialties.id', '=', 'days.event_specialty_id')
            ->where('event_specialties.event_id', $eventId)
            ->whereDate('days.day_date', QueueService::today())
            ->selectRaw('COUNT(*) as c, MAX(doctor_attendances.updated_at) as u')
            ->first();
        $parts['doctors'] = md5("{$doctors->c}|{$doctors->u}");

        $parts['open'] = md5(EventSpecialty::where('event_id', $eventId)
            ->whereDate('opened_on', QueueService::today())
            ->orderBy('id')
            ->pluck('id')
            ->implode(','));

        ksort($parts);

        return ['version' => md5(implode('|', $parts)), 'parts' => $parts];
    }
}
