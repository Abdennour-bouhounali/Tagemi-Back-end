<?php

namespace App\Http\Controllers;

use App\Exceptions\RuleException;
use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Services\QueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Presence desk: some people register twice (two forms instead of one form
 * with several specialties). Same name + same phone = the same person; the
 * desk merges the other registrations into the scanned patient number, so
 * one QR code covers all their appointments.
 */
class PatientMergeController extends Controller
{
    /** GET /api/events/{eventId}/patients/{patientId}/duplicates */
    public function duplicates($eventId, $patientId)
    {
        $patient = $this->patientRows((int) $eventId, (int) $patientId);

        return response()->json([
            'patient_ids' => $this->duplicatesOf($patient->first())->keys()->values(),
        ]);
    }

    /**
     * Every group of patient numbers that look like one person registered
     * several times (same name + phone), for the desk's «التسجيلات المكررة» tab.
     * GET /api/events/{eventId}/duplicates
     */
    public function eventDuplicates($eventId)
    {
        $groups = Appointment::with('eventSpecialty.specialty:id,name')
            ->where('event_id', $eventId)
            ->orderBy('patient_id')->orderByDesc('specialty_order')->orderBy('id')
            ->get(['id', 'patient_id', 'full_name', 'phone', 'birthday', 'event_specialty_id', 'status', 'specialty_order'])
            ->filter(fn ($a) => strlen(PublicTurnController::phoneKey($a->phone)) === 9)
            ->groupBy(fn ($a) => Appointment::nameKey($a->full_name) . '|' . PublicTurnController::phoneKey($a->phone))
            ->filter(fn ($rows) => $rows->pluck('patient_id')->unique()->count() > 1)
            ->map(function ($rows) {
                $first = $rows->first();

                return [
                    'full_name' => $first->full_name,
                    'phone' => $first->phone,
                    'patients' => $rows->groupBy('patient_id')->map(fn ($own, $patientId) => [
                        'patient_id' => (int) $patientId,
                        'birthday' => $own->first()->birthday ? substr((string) $own->first()->birthday, 0, 10) : null,
                        'specialties' => $own->map(fn ($a) => $a->eventSpecialty?->specialty?->name)->filter()->values(),
                        'checked_in' => $own->contains(fn ($a) => $a->status !== 'Pending'),
                    ])->values(),
                ];
            })
            ->values();

        return response()->json(['groups' => $groups]);
    }

    /**
     * Moves the appointments of the given patient numbers to this patient.
     * POST /api/events/{eventId}/patients/{patientId}/merge  {patient_ids: [..]}
     */
    public function merge(Request $request, $eventId, $patientId)
    {
        $data = $request->validate([
            'patient_ids' => 'required|array|min:1',
            'patient_ids.*' => 'integer|distinct',
        ]);

        return DB::transaction(function () use ($data, $eventId, $patientId) {
            $target = $this->patientRows((int) $eventId, (int) $patientId, true);
            $duplicates = $this->duplicatesOf($target->first());

            $queues = app(QueueService::class);
            if ($target->contains(fn ($a) => $a->status !== 'Pending' && $queues->isToday($a))) {
                throw new RuleException('الزائر مسجل حضوره اليوم، لا يمكن الدمج الآن', 409);
            }

            $isSpecial = $target->contains('is_special', true);
            $moved = 0;
            foreach ($data['patient_ids'] as $otherId) {
                $rows = $duplicates->get((int) $otherId);
                if (!$rows) {
                    throw new RuleException("الزائر #{$otherId} ليس بنفس الاسم والهاتف، لا يمكن دمجه", 422);
                }
                if ($rows->contains(fn ($a) => $a->status !== 'Pending')) {
                    throw new RuleException("الزائر #{$otherId} سُجّل حضوره، لا يمكن دمجه", 409);
                }
                foreach ($rows as $appointment) {
                    $appointment->loadMissing(['eventSpecialty.specialty', 'day', 'hour']);
                    $appointment->patient_id = (int) $patientId;
                    $appointment->specialty_order = 0; // the scanned patient's primary stays primary
                    $appointment->is_special = $appointment->is_special || $isSpecial;
                    $appointment->save();
                    AppointmentLog::record('merged', $appointment, [
                        'from_patient_id' => (int) $otherId,
                        'specialty' => $appointment->eventSpecialty?->specialty?->name,
                        'day' => $appointment->day?->day_date ? substr((string) $appointment->day->day_date, 0, 10) : null,
                        'hour' => $appointment->hour?->time,
                    ]);
                    $moved++;
                }
            }

            return response()->json([
                'message' => "دُمج {$moved} موعد في رقم الزائر #{$patientId}",
            ]);
        });
    }

    private function patientRows(int $eventId, int $patientId, bool $lock = false): Collection
    {
        $rows = Appointment::where('event_id', $eventId)->where('patient_id', $patientId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
        if ($rows->isEmpty()) {
            throw new RuleException('لا يوجد زائر بهذا الرقم في هذا الحدث', 404);
        }

        return $rows;
    }

    /** Other patient numbers of the event with the same name and phone (last 9 digits), keyed by patient_id. */
    private function duplicatesOf(Appointment $appointment): Collection
    {
        $phone = PublicTurnController::phoneKey($appointment->phone);
        if (strlen($phone) !== 9) {
            return collect();
        }
        $name = Appointment::nameKey($appointment->full_name);

        return Appointment::where('event_id', $appointment->event_id)
            ->where('patient_id', '!=', $appointment->patient_id)
            ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 9) = ?", [$phone])
            ->orderBy('id')
            ->get()
            ->filter(fn ($a) => Appointment::nameKey($a->full_name) === $name)
            ->groupBy('patient_id');
    }
}
