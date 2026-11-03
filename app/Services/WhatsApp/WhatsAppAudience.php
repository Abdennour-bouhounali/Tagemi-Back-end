<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\Event;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Who receives a WhatsApp campaign and what goes in each {{variable}}.
 * One message per patient number (not per appointment): a patient with three
 * specialties gets one message that lists them.
 */
class WhatsAppAudience
{
    /** Patient fields a template variable can take, with their label for the UI. */
    public const FIELDS = [
        'full_name' => 'الاسم الكامل',
        'first_name' => 'الاسم الأول',
        'patient_id' => 'رقم الزائر',
        'event_name' => 'اسم الحدث',
        'specialties' => 'التخصصات',
        'date' => 'تاريخ الموعد',
        'time' => 'ساعة الموعد',
        'arrival_time' => 'ساعة الحضور (قبل الموعد بساعة)',
        'appointments' => 'كل المواعيد (تخصص، يوم، ساعة)',
        'evaluation_link' => 'رابط تقييم الزيارة',
        'my_turn_link' => 'رابط متابعة الدور',
    ];

    /** Status groups the UI filters on (same groups as the presence desk). */
    public const STATUS_GROUPS = [
        'pending' => ['Pending'],
        'waiting' => ['Waiting'],
        'in_queue' => ['Present', 'Commingsoon'],
        'dilatation' => ['DilatationSent', 'Dilatation'],
        'in_visit' => ['Entered'],
        'completed' => ['Completed'],
    ];

    /**
     * filters: specialties[] (event_specialty ids), days[] (Y-m-d),
     * statuses[] (STATUS_GROUPS keys), reminded all|yes|no, patient_ids[],
     * skip_already_sent (bool, with $templateName).
     *
     * @return Collection<int, Collection<int, Appointment>> patient_id => their appointments
     */
    public function patients(Event $event, array $filters, ?string $templateName = null): Collection
    {
        $query = Appointment::where('event_id', $event->id);

        if (!empty($filters['patient_ids'])) {
            $query->whereIn('patient_id', array_map('intval', $filters['patient_ids']));
        }
        if (!empty($filters['specialties'])) {
            $query->whereIn('event_specialty_id', array_map('intval', $filters['specialties']));
        }
        if (!empty($filters['days'])) {
            $query->whereHas('day', fn ($q) => $q->whereIn(\DB::raw('DATE(day_date)'), $filters['days']));
        }
        if (!empty($filters['statuses'])) {
            $statuses = collect($filters['statuses'])->flatMap(fn ($g) => self::STATUS_GROUPS[$g] ?? [])->all();
            $query->whereIn('status', $statuses ?: ['__none__']);
        }
        $reminded = $filters['reminded'] ?? 'all';
        if ($reminded === 'yes') {
            $query->where('is_reminded', true);
        } elseif ($reminded === 'no') {
            $query->where(fn ($q) => $q->where('is_reminded', false)->orWhereNull('is_reminded'));
        }

        $patientIds = $query->distinct()->pluck('patient_id');

        if (($filters['skip_already_sent'] ?? false) && $templateName) {
            $done = WhatsAppMessage::where('event_id', $event->id)
                ->whereIn('status', ['sent', 'delivered', 'read'])
                ->whereHas('campaign', fn ($q) => $q->where('template_name', $templateName))
                ->pluck('patient_id');
            $patientIds = $patientIds->diff($done);
        }

        // All appointments of each chosen patient (the message lists them all)
        return Appointment::with(['eventSpecialty.specialty', 'day', 'hour'])
            ->where('event_id', $event->id)
            ->whereIn('patient_id', $patientIds)
            ->orderBy('patient_id')->orderByDesc('specialty_order')->orderBy('id')
            ->get()
            ->groupBy('patient_id');
    }

    /** Values of every field for one patient. */
    public function values(Event $event, Collection $appointments): array
    {
        $first = $appointments->first();
        $sorted = $appointments->sortBy(fn ($a) => ($a->day?->day_date ? Carbon::parse($a->day->day_date)->format('Y-m-d') : '9999') . ' ' . $a->hour?->time)->values();
        $earliest = $sorted->first();
        $time = $earliest?->hour?->time ? substr((string) $earliest->hour->time, 0, 5) : null;
        $front = rtrim((string) config('services.frontend_url'), '/');

        return [
            'full_name' => $first->full_name,
            'first_name' => explode(' ', trim((string) $first->full_name))[0] ?? $first->full_name,
            'patient_id' => (string) $first->patient_id,
            'event_name' => $event->name,
            'specialties' => $sorted->map(fn ($a) => $a->eventSpecialty?->specialty?->name)->filter()->unique()->implode('، '),
            'date' => $earliest?->day?->day_date ? Carbon::parse($earliest->day->day_date)->format('Y-m-d') : null,
            'time' => $time,
            'arrival_time' => $time ? Carbon::createFromFormat('H:i', $time)->subHour()->format('H:i') : null,
            'appointments' => $sorted->map(fn ($a) => trim(($a->eventSpecialty?->specialty?->name ?? '') . ' '
                . ($a->day?->day_date ? Carbon::parse($a->day->day_date)->format('Y-m-d') : '') . ' '
                . ($a->hour?->time ? substr((string) $a->hour->time, 0, 5) : '')))->implode(' • '),
            'evaluation_link' => "{$front}/evaluation/{$event->id}/{$first->id}",
            'my_turn_link' => "{$front}/my-turn/{$event->id}",
        ];
    }

    /**
     * Template parameters for one patient. $variables: [placeholder => ['field' => key] | ['text' => fixed]].
     */
    public function parameters(array $variables, array $values): array
    {
        return collect($variables)->map(function ($v) use ($values) {
            if (isset($v['field'])) {
                return WhatsAppClient::cleanParameter($values[$v['field']] ?? '');
            }

            return WhatsAppClient::cleanParameter($v['text'] ?? '');
        })->all();
    }

    /** The phone to send to: the main one, else the second one. */
    public function phoneOf(Collection $appointments): ?string
    {
        $first = $appointments->first();

        return WhatsAppClient::normalizePhone($first->phone) ?? WhatsAppClient::normalizePhone($first->phone2);
    }
}
