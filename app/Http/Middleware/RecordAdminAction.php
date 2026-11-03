<?php

namespace App\Http\Middleware;

use App\Models\AdminAction;
use App\Models\Appointment;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit trail: every change request (POST/PUT/PATCH/DELETE) made by a staff
 * account, and every login attempt on a staff account, is recorded in
 * admin_actions with who, when, what and the result. The super admin reads
 * them on the «سجل العمليات» page. Patients (simple users) are not tracked.
 */
class RecordAdminAction
{
    // Autosave of the event form: one line per keystroke pause would drown the rest
    private const SKIPPED = ['EventDraftController@store', 'EventDraftController@update', 'EventDraftController@destroy'];
    private const SECRET = '/pass|token|secret|otp/i';
    private const MAX_STRING = 200;

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Read before the request runs: a deleted appointment is gone afterwards
        $appointment = $this->appointmentOf($request);
        $response = $next($request);

        try {
            $this->record($request, $response, $appointment);
        } catch (\Throwable $e) {
            // The audit must never break the action itself
            Log::error('Admin action not recorded', ['path' => $request->path(), 'error' => $e->getMessage()]);
        }

        return $response;
    }

    private function record(Request $request, Response $response, ?Appointment $appointment): void
    {
        $action = Str::afterLast((string) $request->route()?->getActionName(), '\\');
        if ($action === '' || in_array($action, self::SKIPPED, true)) {
            return;
        }

        $user = $request->user();
        if (!$user && $action === 'AuthController@login') {
            $user = User::where('email', (string) $request->input('email'))->first();
        }
        if (!$user || (int) $user->role_id === User::ROLE_SIMPLE_USER) {
            return;
        }

        $params = collect($request->route()?->parameters() ?? [])
            ->map(fn ($v) => is_object($v) ? $v->getKey() : $v)
            ->all();
        $subject = array_filter(collect($params)->except(['patientId'])->all() + [
            'patient_id' => is_numeric($patient = $appointment?->patient_id ?? $params['patientId'] ?? $request->input('patient_id')) ? (int) $patient : null,
            'full_name' => $appointment?->full_name,
        ], fn ($v) => $v !== null && $v !== '');

        AdminAction::create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'role_id' => $user->role_id,
            'event_id' => $this->eventOf($request, $params, $appointment) ?? $user->event_id,
            'action' => $action,
            'method' => $request->method(),
            'path' => Str::limit($request->path(), 250, ''),
            'subject' => $subject ?: null,
            'input' => $this->clean($request->input()) ?: null,
            'status' => $response->getStatusCode(),
            'ip' => $request->ip(),
        ]);
    }

    /** The appointment a route acts on (appointments/{id}, waitinglist/X/{id}), if any. */
    private function appointmentOf(Request $request): ?Appointment
    {
        $route = $request->route();
        if (!$route || !preg_match('#^api/(appointments|waitinglist)/#', $request->path())) {
            return null;
        }
        $id = $route->parameter('appointment') ?? $route->parameter('id');
        $id = is_object($id) ? $id->getKey() : $id;

        return is_numeric($id) ? Appointment::select('id', 'event_id', 'patient_id', 'full_name')->find($id) : null;
    }

    private function eventOf(Request $request, array $params, ?Appointment $appointment): ?int
    {
        $id = $params['eventId'] ?? $params['event_id']
            ?? (str_starts_with($request->path(), 'api/events/') ? ($params['id'] ?? null) : null)
            ?? $appointment?->event_id
            ?? $request->input('event_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** Request data without secrets or files, long texts cut. */
    private function clean(array $input, int $depth = 0): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET, $key)) {
                $out[$key] = '***';
            } elseif (is_array($value)) {
                $out[$key] = $depth < 3 ? $this->clean($value, $depth + 1) : '…';
            } elseif (is_string($value)) {
                $out[$key] = Str::limit($value, self::MAX_STRING);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
