<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentArchive;
use App\Support\VisitStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Right of access (law 18-07): a patient sees every piece of data the
 * foundation keeps about them, live and archived. They prove who they are
 * with three things printed on their booking receipt or known only to them:
 * patient number, phone number and birth date.
 */
class PatientDataController extends Controller
{
    private const STATUS_LABELS = [
        'pending' => 'لم يحضر',
        'waiting' => 'حضر، في الانتظار',
        'in_queue' => 'في الطابور',
        'dilatation' => 'في التمدد',
        'completed' => 'أنهى الفحص',
    ];

    public function show(Request $request)
    {
        $data = $request->validate([
            'patient_id' => 'required|integer|min:1',
            'phone' => 'required|string|max:20',
            'birthday' => 'required|date',
        ]);

        $phone = PublicTurnController::phoneKey($data['phone']);
        $birthday = Carbon::parse($data['birthday'])->toDateString();

        $match = $this->rows(fn ($q) => $q->where('patient_id', $data['patient_id']))
            ->first(fn ($row) => $this->owns($row, $phone, $birthday));

        if (!$match) {
            return response()->json([
                'message' => 'لم نجد بيانات توافق هذه المعلومات. تأكد من رقم المريض ورقم الهاتف وتاريخ الميلاد كما كتبتها عند الحجز.',
            ], 404);
        }

        // Every booking of the same person (same name and birth date) made with the same phone
        $rows = $this->rows(fn ($q) => $match->identity_key
                ? $q->where('identity_key', $match->identity_key)
                : $q->where('patient_id', $match->patient_id))
            ->filter(fn ($row) => $this->owns($row, $phone, $birthday))
            ->sortByDesc('created_at')
            ->values();

        $latest = $rows->first();

        return response()->json([
            'person' => [
                'full_name' => $latest->full_name,
                'birthday' => $birthday,
                'sex' => in_array($latest->sex, ['0', 0], true) ? 'ذكر' : 'أنثى',
                'state' => $latest->state,
                'city' => $latest->city,
                'phone' => $latest->phone,
                'phone2' => $latest->phone2 ?: null,
                'diseases' => $latest->diseases && $latest->diseases !== 'nothing' ? $latest->diseases : null,
            ],
            'visits' => $rows->map(fn ($row) => [
                'event' => $row->eventSpecialty?->event?->name,
                'patient_id' => $row->patient_id,
                'specialty' => $row->eventSpecialty?->specialty?->name,
                'date' => $row->day?->day_date ? Carbon::parse($row->day->day_date)->toDateString() : null,
                'time' => $row->hour?->time ? substr($row->hour->time, 0, 5) : null,
                'status' => self::STATUS_LABELS[VisitStatistics::groupOf($row->status)],
                'comment' => $row->comment ?: null,
                'documents_count' => count($row->medical_documents ?: []),
                'booked_at' => $row->created_at?->toDateTimeString(),
                'consent_at' => $row->data_consent_at ? Carbon::parse($row->data_consent_at)->toDateTimeString() : null,
            ])->values(),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    private function rows(callable $scope): Collection
    {
        $with = ['eventSpecialty.specialty', 'eventSpecialty.event', 'day', 'hour'];

        return $scope(Appointment::with($with))->get()
            ->concat($scope(AppointmentArchive::with($with))->get());
    }

    private function owns($row, string $phone, string $birthday): bool
    {
        if (strlen($phone) < 9 || !$row->birthday || Carbon::parse($row->birthday)->toDateString() !== $birthday) {
            return false;
        }

        return in_array($phone, [
            PublicTurnController::phoneKey((string) $row->phone),
            PublicTurnController::phoneKey((string) $row->phone2),
        ], true);
    }
}
