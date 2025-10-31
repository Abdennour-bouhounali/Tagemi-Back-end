<?php

namespace App\Http\Controllers;

use DateTime;
use DateInterval;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Specialty;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\EventSpecialty;
use App\Models\Parameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
class WaitingListController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum')
        ];
    }

    /**
     * Helper method to get max orderList for a specialty across all event_specialties in an event
     * 
     * @param int $eventId
     * @param int $specialtyId
     * @param int|null $excludeAppointmentId - Appointment ID to exclude from max calculation
     * @return int
     */
    private function getMaxOrderListForSpecialty($eventId, $specialtyId, $excludeAppointmentId = null)
    {
        // Get ALL event_specialty IDs for this specialty in this event
        $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
            ->where('specialty_id', $specialtyId)
            ->pluck('id')
            ->toArray();

        if (empty($eventSpecialtyIds)) {
            return 0;
        }

        // Get the maximum orderList across ALL appointments of this specialty in this event
        $query = Appointment::whereIn('event_specialty_id', $eventSpecialtyIds)
            ->where('status', 'Present');

        if ($excludeAppointmentId) {
            $query->where('id', '!=', $excludeAppointmentId);
        }

        $maxOrderList = $query->max('orderList');

        Log::info('Max orderList calculated:', [
            'event_id' => $eventId,
            'specialty_id' => $specialtyId,
            'event_specialty_ids' => $eventSpecialtyIds,
            'excluded_appointment' => $excludeAppointmentId,
            'max_orderList' => $maxOrderList
        ]);

        return $maxOrderList ?? 0;
    }

    /**
     * Get event from request or use current event as fallback
     */
    private function getEventFromRequest(Request $request)
    {
        $eventId = $request->input('event_id') ?? $request->route('event_id');
        
        if ($eventId) {
            $event = Event::find($eventId);
            if (!$event || $event->is_archived) {
                return null;
            }
            return $event;
        }
        
        // Fallback to current event if no event_id provided
        return Event::where('is_current', 1)->first();
    }

    public function getTime()
    {
        $timezone = 'Africa/Algiers';
        $currentTime = Carbon::now(new \DateTimeZone($timezone));
        return $currentTime->format('H:i:s');
    }

    /**
     * Get present appointments grouped by specialty
     */
    public function index()
    {
        $user_role = Auth::user()->role_id;
        $event = Event::where('is_current', 1)->first();

        if (!$event) {
            return response()->json(['message' => 'لا يوجد حدث نشط حالياً.'], 404);
        }

        if ($user_role == 1 || $user_role == 4 || $user_role == 6) {
            $presents = Appointment::whereHas('eventSpecialty', function($query) use ($event) {
                    $query->where('event_id', $event->id);
                })
                ->where('status', 'Present')
                ->with(['eventSpecialty.specialty'])
                ->select('full_name', 'event_specialty_id', 'specialty_order')
                ->get();

            return response()->json(['presents' => $presents]);
        } else {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    }

    /**
     * Get waiting list - first 10 present appointments per specialty
     */
/**
 * Get waiting list - first 10 present appointments per specialty for specific event
 * Supports both route parameter (/api/waitinglist/getwaitinglist/{id}) 
 * and query parameter (/api/waitinglist/getwaitinglist?event_id={id})
 * 
 * @param Request $request
 * @param int|null $id Optional route parameter
 * @return \Illuminate\Http\JsonResponse
 */
/**
 * Get waiting list - BULLETPROOF VERSION
 */
public function getwaitinglist(Request $request, $id = null)
{
    $eventId = $id ?? $request->query('event_id') ?? $request->input('event_id');
    try {
        // Get event ID from parameter or query
        $eventId = $id ?? $request->query('event_id') ?? $request->input('event_id');
        
        if (!$eventId) {
            return response()->json([
                'message' => 'No event ID provided',
                'data' => []
            ], 400);
        }

        // Find event
        $event = Event::find($eventId);
        
        if (!$event) {
            return response()->json([
                'message' => 'Event not found',
                'data' => [],
                'event' => null
            ], 404);
        }

        // Get appointments using DB query (most reliable)
        $results = DB::table('appointments')
            ->join('event_specialties', 'appointments.event_specialty_id', '=', 'event_specialties.id')
            ->join('specialties', 'event_specialties.specialty_id', '=', 'specialties.id')
            ->where('event_specialties.event_id', $eventId)
            ->where('appointments.status', 'Present')
            ->select(
                'appointments.patient_id',
                'appointments.full_name',
                'appointments.orderList',
                'specialties.id as specialty_id',
                'specialties.name as specialty_name'
            )
            ->orderBy('specialties.id')
            ->orderBy('appointments.orderList')
            ->get();

        // Group by specialty and take first 10
        $grouped = [];
        foreach ($results as $row) {
            $specialtyId = $row->specialty_id;
            
            if (!isset($grouped[$specialtyId])) {
                $grouped[$specialtyId] = [];
            }
            
            // Only take first 10 per specialty
            if (count($grouped[$specialtyId]) < 10) {
                $grouped[$specialtyId][] = [
                    'patient_id' => $row->patient_id,
                    'full_name' => $row->full_name,
                    'order' => $row->orderList ?? 0,
                    'position' => 1,
                    'is_priority' => false,
                    'is_special' => false,
                    'status' => 'Present'
                ];
            }
        }

        // Return response
        return response()->json([
            'data' => $grouped,
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'is_current' => isset($event->is_current) ? (bool)$event->is_current : false
            ],
            'timestamp' => now()->toIso8601String(),
            'total_patients' => count($results),
            'specialties_count' => count($grouped)
        ], 200);

    } catch (\Exception $e) {
        // Log the error
        Log::error('Error in getwaitinglist: ' . $e->getMessage(), [
            'event_id' => $eventId ?? null,
            'line' => $e->getLine(),
            'file' => $e->getFile()
        ]);

        // Return error with details
        return response()->json([
            'message' => 'Server error',
            'error' => $e->getMessage(),
            'line' => $e->getLine(),
            'data' => []
        ], 500);
    }
}
    /**
     * Delete all appointments and reset system
     */
    public function deleteAppointmentsAndAdmins()
    {
        Appointment::truncate();
        User::where('role_id', '<>', 1)->delete();

        return response()->json(['message' => 'All appointments and admins (except superadmin) have been deleted successfully.']);
    }

    /**
     * Mark appointment as completed and move to next specialty
     * UPDATED: Uses event-wide orderList calculation
     */
/**
 * Mark appointment as completed and move to next specialty
 * When completed, the Waiting appointment becomes Present
 */
public function Completed($id)
{
    $user_role = Auth::user()->role_id;
    
    if (!in_array($user_role, [1, 4])) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }

    $appointment = Appointment::with('eventSpecialty')->find($id);
    if (!$appointment) {
        return response()->json(['message' => 'No appointment found'], 404);
    }

    $patientId = $appointment->patient_id;

    // Mark current appointment as completed
    $appointment->status = 'Completed';
    $appointment->orderList = 0; // Reset order
    $appointment->save();

    // Find next Waiting appointment for this patient
    $nextAppointment = Appointment::with('eventSpecialty.specialty')
        ->where('patient_id', $patientId)
        ->where('status', 'Waiting')
        ->orderBy('specialty_order', 'asc')
        ->orderBy('created_at', 'asc')
        ->first();

    if ($nextAppointment) {
        $nextEventSpecialty = $nextAppointment->eventSpecialty;
        
        if ($nextEventSpecialty) {
            $eventId = $nextEventSpecialty->event_id;
            $specialtyId = $nextEventSpecialty->specialty_id;

            // Get event specialty IDs
            $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
                ->where('specialty_id', $specialtyId)
                ->pluck('id')
                ->toArray();

            // Get max orderList for this specialty
            $maxPosition = Appointment::whereIn('event_specialty_id', $eventSpecialtyIds)
                ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
                ->max('orderList');

            // Change from Waiting to Present
            $nextAppointment->status = 'Present';
            $nextAppointment->orderList = $maxPosition ? $maxPosition + 1 : 1;
            $nextAppointment->save();

            Log::info('Patient completed first specialty, moved to second:', [
                'patient_id' => $patientId,
                'completed_appointment' => $id,
                'next_appointment' => $nextAppointment->id,
                'next_specialty' => $nextEventSpecialty->specialty->name ?? '',
                'assigned_orderList' => $nextAppointment->orderList
            ]);

            return response()->json([
                'message' => 'Patient completed and moved to next specialty',
                'next_specialty' => $nextEventSpecialty->specialty->name ?? '',
                'next_appointment' => $nextAppointment
            ], 200);
        }
    }

    return response()->json([
        'message' => 'Patient completed all specialties'
    ], 200);
}

 /**
 * Mark appointment as Special Case
 * Special cases have orderList = 0 (first in line)
 */
public function Special($id)
{
    $user_role = Auth::user()->role_id;
    
    if (!in_array($user_role, [1, 4])) {
        return response()->json([
            'success' => false,
            'message' => 'You are not authorized'
        ], 403);
    }

    $appointment = Appointment::with('eventSpecialty')->find($id);
    if (!$appointment) {
        return response()->json([
            'success' => false,
            'message' => 'الموعد غير موجود'
        ], 404);
    }

    DB::beginTransaction();
    try {
        // Mark as Special (priority - first in line)
        $appointment->status = 'Present';
        $appointment->orderList = 0; // First in line
        $appointment->position = 0;
        $appointment->is_special = true;
        $appointment->save();

        DB::commit();

        Log::info('Appointment marked as Special Case:', [
            'appointment_id' => $id,
            'patient_id' => $appointment->patient_id,
            'patient_name' => $appointment->full_name,
            'marked_by' => Auth::id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديد المريض كحالة خاصة (أولوية)',
            'appointment' => $appointment
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Error marking appointment as Special:', [
            'appointment_id' => $id,
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء تحديد الحالة الخاصة'
        ], 500);
    }
}

    /**
     * Mark appointment for dilatation (طب الأسنان)
     */
    public function Dilatation($id)
    {
        $user_role = Auth::user()->role_id;
        
        if (!in_array($user_role, [1, 4])) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $appointment = Appointment::find($id);
        if (!$appointment) {
            return response()->json(['message' => 'No appointment found'], 404);
        }

        $appointment->status = 'Dilatation';
        $appointment->save();

        Log::info('Patient marked for dilatation:', [
            'appointment_id' => $id,
            'patient_id' => $appointment->patient_id
        ]);

        return response()->json(['message' => 'Patient marked for dilatation'], 200);
    }

    /**
     * Finish dilatation - move to priority position
     */
    public function FinishedDilatation($id)
    {
        $user_role = Auth::user()->role_id;
        
        if (!in_array($user_role, [1, 4])) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $appointment = Appointment::find($id);
        if (!$appointment) {
            return response()->json(['message' => 'No appointment found'], 404);
        }

        // Priority position (0 = first in line)
        $appointment->orderList = 0;
        $appointment->status = 'Present';
        $appointment->save();

        Log::info('Patient moved to first position after dilatation:', [
            'appointment_id' => $id,
            'patient_id' => $appointment->patient_id,
            'orderList' => 0
        ]);

        return response()->json(['message' => 'Patient moved to first position'], 200);
    }

/**
 * Mark as coming soon - patient is about to arrive
 * OrderList = max + 10
 */
public function Commingsoon(Request $request, $id)
{
    $user_role = Auth::user()->role_id;
    
    if (!in_array($user_role, [1, 4])) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }

    $appointment = Appointment::with('eventSpecialty')->find($id);
    if (!$appointment) {
        return response()->json(['message' => 'No appointment found'], 404);
    }

    $eventSpecialty = $appointment->eventSpecialty;
    
    if ($eventSpecialty) {
        $eventId = $eventSpecialty->event_id;
        $specialtyId = $eventSpecialty->specialty_id;

        $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
            ->where('specialty_id', $specialtyId)
            ->pluck('id')
            ->toArray();

        // Get max from Present, Waiting, Commingsoon
        $maxPosition = Appointment::whereIn('event_specialty_id', $eventSpecialtyIds)
            ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
            ->where('id', '!=', $id)
            ->max('orderList');

        // Set to max + 10
        $appointment->orderList = $maxPosition ? $maxPosition + 10 : 10;
        $appointment->status = 'Commingsoon';
        $appointment->save();

        Log::info('Patient marked as coming soon:', [
            'appointment_id' => $id,
            'patient_id' => $appointment->patient_id,
            'orderList' => $appointment->orderList,
            'max_was' => $maxPosition
        ]);

        return response()->json([
            'message' => 'Patient marked as coming soon',
            'position' => $appointment->orderList
        ], 200);
    }

    return response()->json(['message' => 'Could not determine event specialty'], 500);
}

    /**
     * Swap specialty order for patient with multiple appointments
     */
    public function alterSpeciality($id)
    {
        $user_role = Auth::user()->role_id;
        
        if (!in_array($user_role, [1, 4])) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $appointment = Appointment::find($id);
        if (!$appointment) {
            return response()->json(['message' => 'No appointment found'], 404);
        }

        $patientAppointments = Appointment::where('patient_id', $appointment->patient_id)
            ->where('id', '!=', $appointment->id)
            ->get();

        if ($patientAppointments->isEmpty()) {
            return response()->json(['message' => 'No other appointments for this patient'], 404);
        }

        $otherAppointment = $patientAppointments->first();

        $tempOrder = $appointment->orderList;
        $appointment->orderList = $otherAppointment->orderList;
        $otherAppointment->orderList = $tempOrder;

        $tempSpecialtyOrder = $appointment->specialty_order;
        $appointment->specialty_order = $otherAppointment->specialty_order;
        $otherAppointment->specialty_order = $tempSpecialtyOrder;

        $appointment->save();
        $otherAppointment->save();

        Log::info('Specialties swapped:', [
            'appointment_1' => $appointment->id,
            'appointment_2' => $otherAppointment->id,
            'patient_id' => $appointment->patient_id
        ]);

        return response()->json([
            'message' => 'Specialties swapped successfully',
            'swapped_with' => $otherAppointment->id,
        ], 200);
    }

/**
 * Mark patient as absent (didn't show up at doctor)
 * Moves to end of Present/Waiting queue
 */
public function Absent($id)
{
    $user_role = Auth::user()->role_id;
    
    if (!in_array($user_role, [1, 4])) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }

    $appointment = Appointment::with('eventSpecialty')->find($id);
    if (!$appointment) {
        return response()->json(['message' => 'No appointment found'], 404);
    }

    $eventSpecialty = $appointment->eventSpecialty;
    
    if ($eventSpecialty) {
        $eventId = $eventSpecialty->event_id;
        $specialtyId = $eventSpecialty->specialty_id;

        $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
            ->where('specialty_id', $specialtyId)
            ->pluck('id')
            ->toArray();

        // Get max from Present, Waiting, Commingsoon (not Absent, Pending, Completed)
        $maxPosition = Appointment::whereIn('event_specialty_id', $eventSpecialtyIds)
            ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
            ->where('id', '!=', $id)
            ->max('orderList');

        // Move to end but keep status as Present
        $appointment->orderList = $maxPosition ? $maxPosition + 1 : 1;
        $appointment->status = 'Present'; // Still in the queue
        $appointment->save();

        Log::info('Patient marked as absent and moved to end:', [
            'appointment_id' => $id,
            'patient_id' => $appointment->patient_id,
            'new_orderList' => $appointment->orderList
        ]);

        return response()->json([
            'message' => 'Patient moved to last position',
            'new_position' => $appointment->orderList
        ], 200);
    }

    return response()->json(['message' => 'Could not determine event specialty'], 500);
}

    /**
     * Get waiting list by event ID and specialty ID
     * Returns ALL appointments for this specialty across ALL event_specialties in the event
     */
    public function GetWaitingListBySpeciality($eventId, $specialityId)
    {
        try {
            $user_role = Auth::user()->role_id;
            
            if (!in_array($user_role, [1, 4, 6])) {
                return response()->json(['message' => 'You are not authorized'], 403);
            }

            $event = Event::find($eventId);
            if (!$event) {
                return response()->json(['message' => 'الحدث غير موجود.'], 404);
            }

            $specialty = Specialty::find($specialityId);
            if (!$specialty) {
                return response()->json(['message' => 'التخصص غير موجود.'], 404);
            }

            // Get ALL event_specialty IDs for this specialty in this event
            $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
                ->where('specialty_id', $specialityId)
                ->pluck('id')
                ->toArray();

            if (empty($eventSpecialtyIds)) {
                return response()->json([
                    'appointments' => [],
                    'speciality' => $specialty->name,
                    'event' => [
                        'id' => $event->id,
                        'name' => $event->name,
                        'is_current' => $event->is_current
                    ],
                    'message' => 'لا توجد مواعيد لهذا التخصص في هذا الحدث'
                ], 200);
            }

            // Get ALL appointments for this specialty across ALL event_specialties
            $appointments = Appointment::with(['day', 'hour', 'eventSpecialty.specialty'])
                ->whereIn('event_specialty_id', $eventSpecialtyIds)
                ->whereIn('status', ['Present', 'Dilatation', 'Commingsoon', 'Waiting'])
                ->orderBy('orderList', 'asc')
                ->get();

            Log::info('Waiting list fetched (event-wide):', [
                'event_id' => $eventId,
                'specialty_id' => $specialityId,
                'event_specialty_ids' => $eventSpecialtyIds,
                'appointments_count' => $appointments->count()
            ]);

            return response()->json([
                'appointments' => $appointments,
                'speciality' => $specialty->name,
                'event_specialty_ids' => $eventSpecialtyIds,
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'is_current' => $event->is_current
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching waiting list:', [
                'event_id' => $eventId,
                'specialty_id' => $specialityId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء تحميل قائمة الانتظار',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * LEGACY: Get waiting list by specialty ID only (uses current event)
     */
    public function GetWaitingListBySpecialityLegacy($specialityId)
    {
        $user_role = Auth::user()->role_id;
        
        if (!in_array($user_role, [1, 4, 6])) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $event = Event::where('is_current', 1)->first();
        if (!$event) {
            return response()->json(['message' => 'لا يوجد حدث نشط حالياً.'], 404);
        }

        return $this->GetWaitingListBySpeciality($event->id, $specialityId);
    }
}