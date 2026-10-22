<?php

namespace App\Http\Middleware;

use App\Models\Appointment;
use App\Models\Day;
use App\Models\EventSpecialty;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff accounts are bound to one event (users.event_id). This middleware
 * resolves which event a request touches and rejects access to any other.
 * The super admin may access every event.
 *
 * Usage: ->middleware('event.access:<type>,<param>')
 *   event            <param> is an event id
 *   appointment      <param> is an appointment id
 *   event_specialty  <param> is an event_specialties id
 *   day              <param> is a days id
 *   own              non-super-admins get <param> forced to their own event id
 *
 * <param> is read from the route first, then from the request input.
 */
class EnsureEventAccess
{
    public function handle(Request $request, Closure $next, string $type, string $param): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if ($type === 'own') {
            if (!$user->event_id) {
                return $this->deny();
            }
            $request->merge([$param => $user->event_id]);
            $request->query->set($param, $user->event_id);
            return $next($request);
        }

        $value = $request->route($param) ?? $request->input($param);

        if ($value === null || $value === '') {
            return $this->deny();
        }

        $eventId = $this->resolveEventId($type, $value);

        if ($eventId === false) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (!$user->canAccessEvent($eventId)) {
            return $this->deny();
        }

        return $next($request);
    }

    /**
     * @return int|null|false  false when the referenced record doesn't exist
     */
    private function resolveEventId(string $type, $value)
    {
        if (is_object($value) && isset($value->event_id)) {
            return $value->event_id;
        }

        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            return false;
        }

        switch ($type) {
            case 'event':
                return (int) $value;
            case 'appointment':
                return Appointment::whereKey($value)->value('event_id') ?? false;
            case 'event_specialty':
                return EventSpecialty::whereKey($value)->value('event_id') ?? false;
            case 'day':
                $day = Day::with('eventSpecialty:id,event_id')->find($value);
                return $day?->eventSpecialty?->event_id ?? false;
        }

        return false;
    }

    private function deny(): Response
    {
        return response()->json(['message' => 'You are not authorized to access this event.'], 403);
    }
}
