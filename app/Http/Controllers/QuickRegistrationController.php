<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Event;
use App\Models\EventSpecialty;
use App\Models\Hour;
use App\Services\QueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Presence desk «إضافة تسجيلات»: reception types registrations in a table,
 * one row per person with one or several specialties. The server picks each
 * specialty's slot (today: the hour of registration; else the next free hour),
 * so the desk only enters the person's data.
 *
 * Every row is saved on its own: a rejected row (duplicate, full specialty,
 * missing field) does not stop the others.
 */
class QuickRegistrationController extends Controller
{
    /**
     * POST /api/events/{eventId}/quick-registrations
     * {rows: [{key, name, lastName, birthday, sex, phone, phone2?, state, city, diseases?, specialties: [event_specialty_id..]}]}
     */
    public function store(Request $request, $eventId)
    {
        $event = Event::findOrFail((int) $eventId);
        if ($event->is_archived) {
            return response()->json(['message' => 'الحدث مؤرشف ولا يمكن الإضافة فيه.'], 403);
        }

        $request->validate([
            'rows' => 'required|array|min:1|max:100',
            'rows.*' => 'array',
        ]);

        $limit = max(1, (int) ($event->nbr_of_allowed_specialties ?? 1));
        $eventSpecialtyIds = EventSpecialty::where('event_id', $event->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Accounts tagged by the super admin may book every specialty of the event
        $max = $request->user()->canExceedSpecialtyLimit() ? max($limit, count($eventSpecialtyIds)) : $limit;

        $results = [];
        foreach ($request->input('rows') as $index => $row) {
            $results[] = ['key' => $row['key'] ?? $index] + $this->storeRow($event, (array) $row, $max, $limit, $eventSpecialtyIds);
        }

        return response()->json(['results' => $results]);
    }

    private function storeRow(Event $event, array $row, int $max, int $limit, array $eventSpecialtyIds): array
    {
        $validator = Validator::make($row, [
            'name' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'birthday' => 'required|date|before:today|after:1900-01-01',
            'sex' => 'required|in:0,1',
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'phone2' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'state' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'diseases' => 'nullable|string|max:500',
            'specialties' => "required|array|min:1|max:{$max}",
            'specialties.*' => 'integer|distinct',
        ], [
            'name.required' => 'الاسم مطلوب',
            'lastName.required' => 'اللقب مطلوب',
            'birthday.required' => 'تاريخ الميلاد مطلوب',
            'birthday.date' => 'تاريخ الميلاد غير صحيح',
            'birthday.before' => 'تاريخ الميلاد غير صحيح',
            'birthday.after' => 'تاريخ الميلاد غير صحيح',
            'sex.required' => 'الجنس مطلوب',
            'sex.in' => 'الجنس مطلوب',
            'phone.required' => 'الهاتف مطلوب',
            'phone.regex' => 'رقم الهاتف غير صحيح',
            'phone2.regex' => 'رقم الهاتف الثاني غير صحيح',
            'state.required' => 'الولاية مطلوبة',
            'city.required' => 'البلدية مطلوبة',
            'specialties.required' => 'اختر تخصصاً واحداً على الأقل',
            'specialties.min' => 'اختر تخصصاً واحداً على الأقل',
            'specialties.max' => "لا يتجاوز عدد التخصصات {$max} في هذا الحدث",
            'specialties.*.distinct' => 'تخصص مكرر',
        ]);
        if ($validator->fails()) {
            return ['ok' => false, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()];
        }
        $data = $validator->validated();

        foreach ($data['specialties'] as $id) {
            if (!in_array((int) $id, $eventSpecialtyIds, true)) {
                return ['ok' => false, 'message' => 'أحد التخصصات لا ينتمي لهذا الحدث'];
            }
        }

        $fullName = trim(preg_replace('/\s+/u', ' ', $data['name'] . ' ' . $data['lastName']));
        $birthday = Carbon::parse($data['birthday'])->format('Y-m-d');

        return DB::transaction(function () use ($event, $data, $fullName, $birthday, $limit) {
            // Same lock as the public booking's patient number: no two rows get one number
            $last = Appointment::lockForUpdate()->orderByDesc('patient_id')->first();
            $patientId = $last ? $last->patient_id + 1 : 1;

            // Same full name + birthday + phone already registered in this event
            $existing = $this->findExisting($event->id, $fullName, $birthday, $data['phone']);
            if ($existing) {
                return [
                    'ok' => false,
                    'duplicate' => true,
                    'patient_id' => $existing->patient_id,
                    'message' => "مسجل مسبقاً في هذا الحدث، رقم الزائر #{$existing->patient_id}",
                ];
            }

            // One specialty per person (name + birthday) per event, a database rule
            $taken = Appointment::with('eventSpecialty.specialty')
                ->where('event_id', $event->id)
                ->where('identity_key', Appointment::identityKey($fullName, $birthday))
                ->whereIn('event_specialty_id', $data['specialties'])
                ->first();
            if ($taken) {
                return [
                    'ok' => false,
                    'patient_id' => $taken->patient_id,
                    'message' => 'شخص بنفس الاسم وتاريخ الميلاد حاجز ' . ($taken->eventSpecialty?->specialty?->name ?? 'هذا التخصص')
                        . " برقم الزائر #{$taken->patient_id}",
                ];
            }

            $slots = [];
            $full = [];
            foreach (array_values($data['specialties']) as $i => $eventSpecialtyId) {
                $eventSpecialty = EventSpecialty::with('specialty')->find($eventSpecialtyId);
                $hour = $this->slotFor($eventSpecialty);
                if ($hour) {
                    $slots[] = [$eventSpecialty, $hour, $i];
                } else {
                    $full[] = $eventSpecialty->specialty->name ?? 'تخصص';
                }
            }

            if ($full) {
                // Nothing saved for this row: the desk removes the full specialty and saves again
                return ['ok' => false, 'message' => 'لا توجد مواعيد متاحة في: ' . implode('، ', $full)];
            }

            $appointments = [];
            foreach ($slots as [$eventSpecialty, $hour, $i]) {
                $appointment = Appointment::create([
                    'user_id' => 1,
                    'event_id' => $event->id,
                    'event_specialty_id' => $eventSpecialty->id,
                    'day_id' => $hour->day_id,
                    'hour_id' => $hour->id,
                    'doctor_id' => null,
                    'full_name' => $fullName,
                    'birthday' => $birthday,
                    'state' => $data['state'],
                    'city' => $data['city'],
                    'diseases' => ($data['diseases'] ?? '') !== '' ? $data['diseases'] : 'nothing',
                    'phone' => $data['phone'],
                    'phone2' => $data['phone2'] ?? '',
                    'sex' => $data['sex'],
                    'patient_id' => $patientId,
                    'specialty_order' => $i === 0 ? 1 : 0,
                    'status' => 'Pending',
                    'position' => $hour->counter + 1,
                    'orderList' => 0,
                    'beyond_limit' => $i >= $limit, // past the event's limit: not counted at check-in
                ]);
                $hour->increment('counter');
                $this->markSaturated($eventSpecialty);

                $appointments[] = [
                    'id' => $appointment->id,
                    'specialty' => $eventSpecialty->specialty->name ?? null,
                    'day' => $hour->day?->day_date ? substr((string) $hour->day->day_date, 0, 10) : null,
                    'hour' => $hour->time,
                ];
            }

            return [
                'ok' => true,
                'patient_id' => $patientId,
                'appointments' => $appointments,
                'message' => "سُجّل برقم الزائر #{$patientId}",
            ];
        });
    }

    /** An appointment of this event with the same name (normalized), birthday and phone (last 9 digits). */
    private function findExisting(int $eventId, string $fullName, string $birthday, string $phone): ?Appointment
    {
        $phoneKey = PublicTurnController::phoneKey($phone);

        return Appointment::where('event_id', $eventId)
            ->where('identity_key', Appointment::identityKey($fullName, $birthday))
            ->get(['id', 'patient_id', 'phone'])
            ->first(fn ($a) => PublicTurnController::phoneKey($a->phone) === $phoneKey);
    }

    /**
     * The slot of a new registration (locked):
     * - the specialty has a day today: the hour of registration (the last hour
     *   that started, or the first one before the day starts), even when full,
     *   since the person is at the desk;
     * - otherwise: the earliest hour with a free place on the next day that has one.
     */
    private function slotFor(EventSpecialty $eventSpecialty): ?Hour
    {
        $todayHours = Hour::with('day')
            ->join('days', 'days.id', '=', 'hours.day_id')
            ->where('days.event_specialty_id', $eventSpecialty->id)
            ->whereDate('days.day_date', QueueService::today())
            ->orderBy('hours.time')->orderBy('hours.id')
            ->select('hours.*')
            ->lockForUpdate()
            ->get();
        if ($todayHours->isNotEmpty()) {
            $now = Carbon::now(QueueService::TIMEZONE)->format('H:i:s');

            return $todayHours->filter(fn ($h) => (string) $h->time <= $now)->last() ?? $todayHours->first();
        }

        return Hour::with('day')
            ->join('days', 'days.id', '=', 'hours.day_id')
            ->where('days.event_specialty_id', $eventSpecialty->id)
            ->whereDate('days.day_date', '>', QueueService::today())
            ->whereColumn('hours.counter', '<', 'hours.max_allowed')
            ->orderBy('days.day_date')->orderBy('hours.time')->orderBy('hours.id')
            ->select('hours.*')
            ->lockForUpdate()
            ->first();
    }

    private function markSaturated(EventSpecialty $eventSpecialty): void
    {
        $hasFree = Hour::whereHas('day', fn ($q) => $q->where('event_specialty_id', $eventSpecialty->id))
            ->whereColumn('counter', '<', 'max_allowed')
            ->exists();
        if (!$hasFree && !$eventSpecialty->is_saturated) {
            $eventSpecialty->is_saturated = true;
            $eventSpecialty->save();
        }
    }
}
