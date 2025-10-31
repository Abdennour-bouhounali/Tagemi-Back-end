<?php

namespace App\Http\Controllers;

use DateTime;
use DateInterval;
use Carbon\Carbon;
use App\Models\Specialty;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\EventSpecialty;
use App\Models\Day;
use App\Models\Hour;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppointmentController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum', except: ['index', 'show', 'store', 'getAvailableSlots'])
        ];
    }

public function updatePresence(Request $request, $id)
{
    try {
        $validated = $request->validate([
            'status' => 'required|in:Present,Absent,Pending,Commingsoon'
        ]);

        $appointment = Appointment::with([
            'eventSpecialty.specialty',
            'eventSpecialty.event',
            'day',
            'hour',
            'doctor'
        ])->find($id);

        if (!$appointment) {
            return response()->json([
                'message' => 'الموعد غير موجود'
            ], 404);
        }

        $oldStatus = $appointment->status;
        $patientId = $appointment->patient_id;

        // PRESENT: Check in at entry - FIRST appointment becomes Present, all others become Waiting
        if ($validated['status'] === 'Present' && $oldStatus !== 'Present') {
            // Get all appointments for this patient in the same event
            $patientAppointments = Appointment::where('patient_id', $patientId)
                ->where('event_id', $appointment->event_id)
                ->whereNotIn('status', ['Absent', 'Completed'])
                ->orderBy('specialty_order', 'desc') // ✅ Order by specialty_order (1 first, then 0)
                ->orderBy('created_at', 'asc')
                ->get();

            Log::info('Patient checking in - found appointments:', [
                'patient_id' => $patientId,
                'clicked_appointment_id' => $id,
                'total_appointments' => $patientAppointments->count(),
                'appointment_ids' => $patientAppointments->pluck('id')->toArray()
            ]);

            foreach ($patientAppointments as $index => $patientAppt) {
                // ✅ The FIRST appointment (index 0) becomes Present
                if ($index === 0) {
                    $patientAppt->status = 'Present';
                    
                    $eventSpecialtyId = $patientAppt->event_specialty_id;
                    $eventSpecialty = EventSpecialty::find($eventSpecialtyId);
                    
                    if ($eventSpecialty) {
                        $specialtyId = $eventSpecialty->specialty_id;
                        $eventId = $eventSpecialty->event_id;
                        
                        $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
                            ->where('specialty_id', $specialtyId)
                            ->pluck('id')
                            ->toArray();
                        
                        // Get max from Present, Waiting, and Commingsoon
                        $maxOrderList = Appointment::whereIn('event_specialty_id', $eventSpecialtyIds)
                            ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
                            ->where('id', '!=', $patientAppt->id)
                            ->max('orderList');
                        
                        $patientAppt->orderList = $maxOrderList ? $maxOrderList + 1 : 1;
                        
                        Log::info('First appointment set to Present:', [
                            'appointment_id' => $patientAppt->id,
                            'orderList' => $patientAppt->orderList
                        ]);
                    } else {
                        $maxOrderList = Appointment::where('event_specialty_id', $eventSpecialtyId)
                            ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
                            ->where('id', '!=', $patientAppt->id)
                            ->max('orderList');
                        
                        $patientAppt->orderList = $maxOrderList ? $maxOrderList + 1 : 1;
                    }
                    
                    $patientAppt->save();
                } 
                // ✅ All other appointments become Waiting
                else {
                    $patientAppt->status = 'Waiting';
                    
                    $eventSpecialtyId = $patientAppt->event_specialty_id;
                    $eventSpecialty = EventSpecialty::find($eventSpecialtyId);
                    
                    if ($eventSpecialty) {
                        $specialtyId = $eventSpecialty->specialty_id;
                        $eventId = $eventSpecialty->event_id;
                        
                        $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
                            ->where('specialty_id', $specialtyId)
                            ->pluck('id')
                            ->toArray();
                        
                        $maxOrderList = Appointment::whereIn('event_specialty_id', $eventSpecialtyIds)
                            ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
                            ->where('id', '!=', $patientAppt->id)
                            ->max('orderList');
                        
                        $patientAppt->orderList = $maxOrderList ? $maxOrderList + 1 : 1;
                        
                        Log::info('Other appointment set to Waiting:', [
                            'appointment_id' => $patientAppt->id,
                            'orderList' => $patientAppt->orderList
                        ]);
                    } else {
                        $maxOrderList = Appointment::where('event_specialty_id', $eventSpecialtyId)
                            ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
                            ->where('id', '!=', $patientAppt->id)
                            ->max('orderList');
                        
                        $patientAppt->orderList = $maxOrderList ? $maxOrderList + 1 : 1;
                    }
                    
                    $patientAppt->save();
                }
            }

            // Reload the current appointment with fresh data
            $appointment = Appointment::with([
                'eventSpecialty.specialty',
                'eventSpecialty.event',
                'day',
                'hour',
                'doctor'
            ])->find($id);

        } 
        // ABSENT: Didn't show up at doctor - move to end
        elseif ($validated['status'] === 'Absent') {
            $eventSpecialty = EventSpecialty::find($appointment->event_specialty_id);
            
            if ($eventSpecialty) {
                $specialtyId = $eventSpecialty->specialty_id;
                $eventId = $eventSpecialty->event_id;
                
                $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
                    ->where('specialty_id', $specialtyId)
                    ->pluck('id')
                    ->toArray();
                
                // Max from Present, Waiting, Commingsoon (not Absent, Pending, Completed)
                $maxOrderList = Appointment::whereIn('event_specialty_id', $eventSpecialtyIds)
                    ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
                    ->where('id', '!=', $appointment->id)
                    ->max('orderList');
                
                $appointment->orderList = $maxOrderList ? $maxOrderList + 1 : 1;
                $appointment->status = 'Present'; // Still present, just moved to end
            }
            
            $appointment->save();

        } 
        // PENDING: Reset to pending
        elseif ($validated['status'] === 'Pending') {
            $appointment->status = 'Pending';
            $appointment->orderList = 0;
            $appointment->save();
        }
        // COMMINGSOON: About to arrive - move to position
        elseif ($validated['status'] === 'Commingsoon') {
            $eventSpecialty = EventSpecialty::find($appointment->event_specialty_id);
            
            if ($eventSpecialty) {
                $specialtyId = $eventSpecialty->specialty_id;
                $eventId = $eventSpecialty->event_id;
                
                $eventSpecialtyIds = EventSpecialty::where('event_id', $eventId)
                    ->where('specialty_id', $specialtyId)
                    ->pluck('id')
                    ->toArray();
                
                $maxOrderList = Appointment::whereIn('event_specialty_id', $eventSpecialtyIds)
                    ->whereIn('status', ['Present', 'Waiting', 'Commingsoon'])
                    ->where('id', '!=', $appointment->id)
                    ->max('orderList');
                
                $appointment->orderList = $maxOrderList ? $maxOrderList + 10 : 10;
                $appointment->status = 'Commingsoon';
            }
            
            $appointment->save();
        }

        Log::info('Presence status updated:', [
            'appointment_id' => $id,
            'patient_id' => $appointment->patient_id,
            'old_status' => $oldStatus,
            'new_status' => $appointment->status,
            'orderList' => $appointment->orderList,
            'updated_by' => Auth::id()
        ]);

        $appointment->load([
            'eventSpecialty.specialty',
            'eventSpecialty.event',
            'day',
            'hour',
            'doctor'
        ]);

        return response()->json([
            'message' => 'تم تحديث حالة الحضور بنجاح',
            'appointment' => $appointment,
            'old_status' => $oldStatus,
            'new_status' => $appointment->status,
            'updated_appointments_count' => isset($patientAppointments) ? $patientAppointments->count() : 1
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'message' => 'خطأ في البيانات المدخلة',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        Log::error('Error updating presence status:', [
            'appointment_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'message' => 'حدث خطأ أثناء تحديث الحالة',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
        ], 500);
    }
}
    /**
     * 🆕 SEARCH APPOINTMENTS - For CheckPresence search functionality
     * GET /api/appointments/search?query=...&event_id=...
     */
    public function search(Request $request)
    {
        try {
            $query = $request->input('query');
            $eventId = $request->input('event_id');

            if (!$query) {
                return response()->json([
                    'message' => 'يرجى إدخال كلمة البحث',
                    'appointments' => []
                ], 400);
            }

            $appointmentsQuery = Appointment::with([
                'eventSpecialty.specialty',
                'eventSpecialty.event',
                'day',
                'hour',
                'doctor'
            ]);

            // Filter by event if provided
            if ($eventId) {
                $appointmentsQuery->whereHas('eventSpecialty', function($q) use ($eventId) {
                    $q->where('event_id', $eventId);
                });
            }

            // Search by patient_id, full_name, or phone
            $appointments = $appointmentsQuery->where(function($q) use ($query) {
                $q->where('patient_id', 'LIKE', "%{$query}%")
                  ->orWhere('full_name', 'LIKE', "%{$query}%")
                  ->orWhere('phone', 'LIKE', "%{$query}%")
                  ->orWhere('phone2', 'LIKE', "%{$query}%");
            })
            ->orderBy('patient_id', 'desc')
            ->limit(50)
            ->get();

            return response()->json([
                'appointments' => $appointments,
                'count' => $appointments->count()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Search appointments error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'حدث خطأ أثناء البحث',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * 🆕 GET EVENT STATISTICS - For CheckPresence statistics
     * GET /api/appointments/event-statistics/{eventId}
     */
    public function getEventStatistics($eventId)
    {
        try {
            $event = Event::find($eventId);

            if (!$event) {
                return response()->json([
                    'message' => 'الحدث غير موجود'
                ], 404);
            }

            // Get all appointments for this event
            $appointments = Appointment::whereHas('eventSpecialty', function($q) use ($eventId) {
                $q->where('event_id', $eventId);
            })->get();

            // Overall statistics
            $statistics = [
                'total' => $appointments->count(),
                'present' => $appointments->where('status', 'Present')->count(),
                'absent' => $appointments->where('status', 'Absent')->count(),
                'pending' => $appointments->where('status', 'Pending')->count(),
                'waiting' => $appointments->where('status', 'Waiting')->count(),
            ];

            // Statistics by specialty
            $bySpecialty = Appointment::whereHas('eventSpecialty', function($q) use ($eventId) {
                    $q->where('event_id', $eventId);
                })
                ->with('eventSpecialty.specialty')
                ->get()
                ->groupBy('event_specialty_id')
                ->map(function($items, $specialtyId) {
                    $specialty = $items->first()->eventSpecialty->specialty;
                    return [
                        'specialty_id' => $specialty->id ?? null,
                        'specialty_name' => $specialty->name ?? 'غير معروف',
                        'total' => $items->count(),
                        'present' => $items->where('status', 'Present')->count(),
                        'absent' => $items->where('status', 'Absent')->count(),
                        'pending' => $items->where('status', 'Pending')->count(),
                        'waiting' => $items->where('status', 'Waiting')->count(),
                    ];
                })
                ->values();

            return response()->json([
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'is_current' => $event->is_current,
                    'is_archived' => $event->is_archived,
                ],
                'statistics' => $statistics,
                'by_specialty' => $bySpecialty
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get event statistics error:', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'حدث خطأ أثناء جلب الإحصائيات',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * GET APPOINTMENTS BY EVENT - For CheckPresence table
     * GET /api/appointments/by-event?event_id=...&status=...&specialty_id=...
     */
    public function getByEvent(Request $request)
    {
        try {
            $validated = $request->validate([
                'event_id' => 'required|exists:events,id',
                'status' => 'nullable|in:Present,Absent,Pending,Waiting,Completed',
                'specialty_id' => 'nullable|exists:specialties,id'
            ]);
            
            $query = Appointment::whereHas('eventSpecialty', function($q) use ($validated) {
                $q->where('event_id', $validated['event_id']);
                
                if (isset($validated['specialty_id'])) {
                    $q->where('specialty_id', $validated['specialty_id']);
                }
            });
            
            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            }
            
            $appointments = $query->with([
                    'eventSpecialty.specialty',
                    'eventSpecialty.event',
                    'day',
                    'hour',
                    'doctor'
                ])
                ->orderBy('day_id', 'asc')
                ->orderBy('hour_id', 'asc')
                ->orderBy('patient_id', 'asc')
                ->get();
            
            return response()->json([
                'appointments' => $appointments,
                'total' => $appointments->count(),
                'statistics' => [
                    'present' => $appointments->where('status', 'Present')->count(),
                    'absent' => $appointments->where('status', 'Absent')->count(),
                    'pending' => $appointments->where('status', 'Pending')->count(),
                    'waiting' => $appointments->where('status', 'Waiting')->count(),
                    'completed' => $appointments->where('status', 'Completed')->count(),
                ]
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error fetching event appointments:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'حدث خطأ أثناء تحميل المواعيد',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Get available time slots for a specific event specialty and day
     */
public function getAvailableSlots(Request $request)
{
    $validated = $request->validate([
        'event_specialty_id' => 'required|exists:event_specialties,id',
        'day_id' => 'required|exists:days,id',
    ]);

    $eventSpecialty = EventSpecialty::find($validated['event_specialty_id']);
    
    if (!$eventSpecialty) {
        return response()->json(['message' => 'Event specialty not found'], 404);
    }

    // Get the day to fetch number_per_hour
    $day = Day::find($validated['day_id']);
    
    if (!$day) {
        return response()->json(['message' => 'Day not found'], 404);
    }

    $hours = Hour::where('day_id', $validated['day_id'])
        ->orderBy('time')
        ->get();

    $maxSlotsPerHour = $day->number_per_hour;

    $availableSlots = [];

    foreach ($hours as $hour) {
        // ✅ Use counter instead of counting appointments
        $remainingSlots = $hour->max_allowed - $hour->counter;

        // ✅ Only include hours with available slots
        if ($remainingSlots > 0) {
            $availableSlots[] = [
                'hour_id' => $hour->id,
                'time' => $hour->time,
                'remaining_slots' => $remainingSlots,
                'booked' => $hour->counter,
                'max_allowed' => $hour->max_allowed,
            ];
        }
    }

    return response()->json([
        'available_slots' => $availableSlots,
        'max_slots_per_hour' => $maxSlotsPerHour,
        'day_date' => $day->day_date,
    ], 200);
}
    /**
     * Store multiple appointments for the same specialty and time slot
     */
    public function storeMultiple(Request $request)
    {
        // Log incoming request for debugging
        Log::info('Multiple appointments store request:', [
            'data' => $request->all()
        ]);

        // Validate input data
        try {
            $validatedData = $request->validate([
                'event_id' => 'required|exists:events,id',
                'event_specialty_id' => 'required|exists:event_specialties,id',
                'day_id' => 'required|exists:days,id',
                'hour_id' => 'required|exists:hours,id',
                'appointments' => 'required|array|min:1',
                'appointments.*.name' => 'required|string|max:255',
                'appointments.*.lastName' => 'required|string|max:255',
                'appointments.*.birthday' => 'required|date|before:today',
                'appointments.*.state' => 'required|string|max:100',
                'appointments.*.city' => 'required|string|max:100',
                'appointments.*.diseases' => 'nullable|string|max:500',
                'appointments.*.phone' => 'required|string|max:20',
                'appointments.*.phone2' => 'required|string|max:20',
                'appointments.*.sex' => 'required|in:0,1',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed:', $e->errors());
            return response()->json([
                'message' => 'خطأ في البيانات المدخلة',
                'errors' => $e->errors()
            ], 422);
        }

        // Verify event exists and is active
        $event = Event::find($validatedData['event_id']);

        if (!$event) {
            return response()->json(['message' => 'الحدث غير موجود.'], 404);
        }

        if ($event->is_archived) {
            return response()->json(['message' => 'الحدث مؤرشف ولا يمكن الحجز فيه.'], 403);
        }

        if (!$event->is_active) {
            return response()->json(['message' => 'الحدث غير نشط حالياً.'], 403);
        }

        // Verify event specialty belongs to this event
        $eventSpecialty = EventSpecialty::with('specialty')->find($validatedData['event_specialty_id']);
        
        if (!$eventSpecialty) {
            return response()->json(['message' => 'التخصص غير موجود.'], 404);
        }

        if ($eventSpecialty->event_id != $event->id) {
            return response()->json(['message' => 'التخصص لا ينتمي لهذا الحدث.'], 422);
        }

        // Verify day belongs to this event specialty
        $dayExists = Day::where('id', $validatedData['day_id'])
            ->where('event_specialty_id', $validatedData['event_specialty_id'])
            ->exists();

        if (!$dayExists) {
            return response()->json(['message' => 'اليوم المختار غير صالح لهذا التخصص.'], 422);
        }

        // Verify hour belongs to this day
        $hourExists = Hour::where('id', $validatedData['hour_id'])
            ->where('day_id', $validatedData['day_id'])
            ->exists();

        if (!$hourExists) {
            return response()->json(['message' => 'الساعة المختارة غير صالحة لهذا اليوم.'], 422);
        }

        // Calculate available slots
        $day = Day::find($validatedData['day_id']);
        $maxSlotsPerHour = $day->number_per_hour;

        // Start transaction
        DB::beginTransaction();
    try {
        // Get the hour and lock it
        $hour = Hour::where('id', $validatedData['hour_id'])
            ->where('day_id', $validatedData['day_id'])
            ->lockForUpdate()
            ->first();

        if (!$hour) {
            return response()->json(['message' => 'الساعة المختارة غير صالحة.'], 422);
        }

        // ✅ Check hour counter
        $availableSlots = $hour->max_allowed - $hour->counter;

        Log::info('Slot availability (counter-based):', [
            'hour_id' => $hour->id,
            'counter' => $hour->counter,
            'max_allowed' => $hour->max_allowed,
            'available' => $availableSlots,
            'requested' => count($validatedData['appointments'])
        ]);

        // Check if enough slots available
        if ($availableSlots <= 0) {
            DB::rollBack();
            return response()->json([
                'message' => 'جميع المواعيد في هذا الوقت ممتلئة.',
                'available_slots' => 0
            ], 409);
        }

        $appointmentsToCreate = min($availableSlots, count($validatedData['appointments']));
        $createdAppointments = [];
        $skippedAppointments = [];
        $patientIds = [];
        $duplicatePatients = [];

        // Get doctor for this event specialty
        $doctor = $eventSpecialty->doctors()->first();

        // Generate starting patient_id with lock
        $lastAppointment = Appointment::lockForUpdate()->orderBy('patient_id', 'desc')->first();
        $nextPatientId = $lastAppointment ? $lastAppointment->patient_id + 1 : 1;

        $successfullyCreated = 0;

        foreach ($validatedData['appointments'] as $index => $appointmentData) {
            // Check if we've reached the slot limit
            if ($index >= $appointmentsToCreate) {
                $skippedAppointments[] = $appointmentData['name'] . ' ' . $appointmentData['lastName'];
                continue;
            }

            // Build full name
            $fullName = trim($appointmentData['name'] . ' ' . $appointmentData['lastName']);

            // Check for duplicate patient in this event
            $existingAppointment = Appointment::whereHas('eventSpecialty', function($query) use ($event) {
                    $query->where('event_id', $event->id);
                })
                ->where('full_name', $fullName)
                ->where('birthday', $appointmentData['birthday'])
                ->first();

            if ($existingAppointment) {
                $duplicatePatients[] = [
                    'name' => $fullName,
                    'patient_id' => $existingAppointment->patient_id
                ];
                Log::info('Duplicate patient found:', [
                    'name' => $fullName,
                    'existing_patient_id' => $existingAppointment->patient_id
                ]);
                continue;
            }

            // Create appointment
            $appointment = Appointment::create([
                'user_id' => 1,
                'event_id' => $validatedData['event_id'],
                'event_specialty_id' => $validatedData['event_specialty_id'],
                'day_id' => $validatedData['day_id'],
                'hour_id' => $validatedData['hour_id'],
                'doctor_id' => $doctor ? $doctor->id : null,
                'full_name' => $fullName,
                'birthday' => $appointmentData['birthday'],
                'state' => $appointmentData['state'],
                'city' => $appointmentData['city'],
                'diseases' => $appointmentData['diseases'] ?? 'nothing',
                'phone' => $appointmentData['phone'],
                'phone2' => $appointmentData['phone2'],
                'sex' => $appointmentData['sex'],
                'patient_id' => $nextPatientId,
                'specialty_order' => 1,
                'status' => 'Pending',
                'position' => $hour->counter + $successfullyCreated + 1,
                'orderList' => 0,
            ]);

            Log::info('Appointment created:', [
                'id' => $appointment->id,
                'patient_id' => $nextPatientId,
                'name' => $fullName
            ]);

            $createdAppointments[] = $appointment->load(['eventSpecialty.specialty', 'day', 'hour']);
            $patientIds[] = $nextPatientId;
            $nextPatientId++;
            $successfullyCreated++;
        }

        // ✅ INCREMENT HOUR COUNTER by number of successfully created appointments
        if ($successfullyCreated > 0) {
            $hour->increment('counter', $successfullyCreated);
            
            Log::info('Hour counter incremented:', [
                'hour_id' => $hour->id,
                'incremented_by' => $successfullyCreated,
                'new_counter' => $hour->counter,
                'max_allowed' => $hour->max_allowed
            ]);
        }

        // Check if at least one appointment was created
        if (empty($createdAppointments)) {
            DB::rollBack();
            
            $message = 'لم يتم إنشاء أي موعد.';
            if (!empty($duplicatePatients)) {
                $duplicateInfo = implode(', ', array_map(function($dup) {
                    return $dup['name'];
                }, $duplicatePatients));
                $message .= ' المرضى التالية لديهم مواعيد مسبقة: ' . $duplicateInfo;
            }
            
            return response()->json([
                'message' => $message,
                'duplicate_patients' => $duplicatePatients
            ], 409);
        }
            DB::commit();

            $message = sprintf(
                'تم حجز %d موعد بنجاح من أصل %d مطلوب!',
                count($createdAppointments),
                count($validatedData['appointments'])
            );

            $response = [
                'message' => $message,
                'appointments' => $createdAppointments,
                'patient_ids' => $patientIds,
                'created_count' => count($createdAppointments),
                'requested_count' => count($validatedData['appointments']),
                'event_id' => $event->id,
                'event_name' => $event->name,
            ];

            // Add warnings if some appointments were skipped or duplicated
            $warnings = [];
            
            if (!empty($skippedAppointments)) {
                $warnings[] = sprintf(
                    'تنبيه: تم تجاوز %d موعد بسبب عدم توفر أماكن كافية. الأسماء: %s',
                    count($skippedAppointments),
                    implode(', ', $skippedAppointments)
                );
            }

            if (!empty($duplicatePatients)) {
                $duplicateInfo = implode(', ', array_map(function($dup) {
                    return $dup['name'] ;
                }, $duplicatePatients));
                $warnings[] = sprintf(
                    'تنبيه: تم تجاوز %d موعد لمرضى لديهم مواعيد مسبقة: %s',
                    count($duplicatePatients),
                    $duplicateInfo
                );
            }

            if (!empty($warnings)) {
                $response['warning_message'] = implode(' | ', $warnings);
                $response['skipped_appointments'] = $skippedAppointments;
                $response['duplicate_patients'] = $duplicatePatients;
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Multiple appointments creation error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'حدث خطأ أثناء حجز المواعيد: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }
/**
 * Recalculate all hour counters based on actual appointments
 * Useful for fixing inconsistencies
 */
public function recalculateCounters()
{
    DB::beginTransaction();
    try {
        $hours = Hour::all();
        $updated = 0;

        foreach ($hours as $hour) {
            // Count actual appointments for this hour
            $actualCount = Appointment::where('hour_id', $hour->id)
                ->whereIn('status', ['Pending', 'Present', 'Waiting', 'Commingsoon'])
                ->count();

            if ($hour->counter != $actualCount) {
                $hour->counter = $actualCount;
                $hour->save();
                $updated++;
                
                Log::info('Counter recalculated:', [
                    'hour_id' => $hour->id,
                    'old_counter' => $hour->counter,
                    'new_counter' => $actualCount
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'message' => 'تم إعادة حساب العدادات بنجاح',
            'updated_count' => $updated
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Recalculate counters error:', [
            'error' => $e->getMessage()
        ]);
        
        return response()->json([
            'message' => 'حدث خطأ أثناء إعادة الحساب'
        ], 500);
    }
}
    /**
     * Check slot availability for given parameters
     */
 public function checkSlotAvailability(Request $request)
{
    try {
        $validatedData = $request->validate([
            'event_specialty_id' => 'required|exists:event_specialties,id',
            'day_id' => 'required|exists:days,id',
            'hour_id' => 'required|exists:hours,id',
        ]);

        $day = Day::find($validatedData['day_id']);
        
        if (!$day) {
            return response()->json(['message' => 'اليوم غير موجود.'], 404);
        }

        $hour = Hour::where('id', $validatedData['hour_id'])
            ->where('day_id', $validatedData['day_id'])
            ->first();

        if (!$hour) {
            return response()->json(['message' => 'الساعة غير موجودة.'], 404);
        }

        // ✅ Use counter for availability
        $available = max(0, $hour->max_allowed - $hour->counter);

        return response()->json([
            'total' => $hour->max_allowed,
            'occupied' => $hour->counter,
            'available' => $available,
            'is_full' => $hour->counter >= $hour->max_allowed,
        ]);

    } catch (\Exception $e) {
        Log::error('Check slot availability error:', ['error' => $e->getMessage()]);
        return response()->json([
            'message' => 'حدث خطأ أثناء التحقق من الأماكن المتاحة.'
        ], 500);
    }
}
    /**
     * Store a newly created appointment in storage with event ID
     */
    public function store(Request $request, $eventID)
    {
        // Log incoming request for debugging
        Log::info('Appointment store request:', [
            'event_id' => $eventID,
            'data' => $request->all()
        ]);

        // First, verify event exists and is active
        $event = Event::find($eventID);

        if (!$event) {
            return response()->json(['message' => 'الحدث غير موجود.'], 404);
        }

        if ($event->is_archived) {
            return response()->json(['message' => 'الحدث مؤرشف ولا يمكن الحجز فيه.'], 403);
        }

        // Validate input data
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'lastName' => 'required|string|max:255',
                'birthday' => 'required|date|before:today',
                'state' => 'required|string|max:100',
                'city' => 'required|string|max:100',
                'diseases' => 'nullable|string|max:500',
                'phone' => 'required|string|max:20',
                'phone2' => 'required|string|max:20',
                'sex' => 'required|in:0,1',
                'specialties' => 'required|array|min:1|max:2',
                'specialties.*.event_specialty_id' => 'required|exists:event_specialties,id',
                'specialties.*.day_id' => 'required|exists:days,id',
                'specialties.*.hour_id' => 'required|exists:hours,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed:', $e->errors());
            return response()->json([
                'message' => 'خطأ في البيانات المدخلة',
                'errors' => $e->errors()
            ], 422);
        }

        // Build full name
        $fullName = trim($validatedData['name'] . ' ' . $validatedData['lastName']);

        // Check for duplicate patient in this event
        $existingAppointment = Appointment::whereHas('eventSpecialty', function($query) use ($event) {
                $query->where('event_id', $event->id);
            })
            ->where('full_name', $fullName)
            ->where('birthday', $validatedData['birthday'])
            ->first();

        if ($existingAppointment) {
            return response()->json([
                'message' => 'لديك موعد مسبق في هذا الحدث. رقم المريض: ' . $existingAppointment->patient_id
            ], 409);
        }

        // Verify all specialties belong to this event and collect them
        $eventSpecialtyIds = EventSpecialty::where('event_id', $event->id)
            ->pluck('id')
            ->toArray();

        foreach ($validatedData['specialties'] as $specialtyData) {
            if (!in_array($specialtyData['event_specialty_id'], $eventSpecialtyIds)) {
                return response()->json([
                    'message' => 'أحد التخصصات المختارة لا ينتمي لهذا الحدث.'
                ], 422);
            }
        }

    // Start transaction
    DB::beginTransaction();
    try {
        // Generate next patient_id with lock to prevent race conditions
        $lastAppointment = Appointment::lockForUpdate()->orderBy('patient_id', 'desc')->first();
        $nextPatientId = $lastAppointment ? $lastAppointment->patient_id + 1 : 1;

        $appointments = [];
        $waitingListSpecialties = [];

        foreach ($validatedData['specialties'] as $index => $specialtyData) {
            $eventSpecialty = EventSpecialty::with('specialty')
                ->find($specialtyData['event_specialty_id']);
            
            if (!$eventSpecialty) {
                Log::error('Event specialty not found:', ['id' => $specialtyData['event_specialty_id']]);
                continue;
            }

            // Double-check event specialty belongs to this event
            if ($eventSpecialty->event_id != $event->id) {
                Log::error('Event specialty mismatch:', [
                    'event_specialty_id' => $eventSpecialty->id,
                    'expected_event_id' => $event->id,
                    'actual_event_id' => $eventSpecialty->event_id
                ]);
                throw new \Exception('تخصص غير صالح للحدث الحالي.');
            }

            // Verify day belongs to this event specialty
            $day = Day::where('id', $specialtyData['day_id'])
                ->where('event_specialty_id', $specialtyData['event_specialty_id'])
                ->first();

            if (!$day) {
                throw new \Exception('اليوم المختار غير صالح لهذا التخصص.');
            }

            // Verify hour belongs to this day
            $hour = Hour::where('id', $specialtyData['hour_id'])
                ->where('day_id', $specialtyData['day_id'])
                ->lockForUpdate()  // ✅ Lock the hour for update
                ->first();

            if (!$hour) {
                throw new \Exception('الساعة المختارة غير صالحة لهذا اليوم.');
            }

            // ✅ Check if hour is already full using counter
            if ($hour->counter >= $hour->max_allowed) {
                $waitingListSpecialties[] = $eventSpecialty->specialty->name;
                Log::info('Slot full (counter check):', [
                    'hour_id' => $hour->id,
                    'counter' => $hour->counter,
                    'max_allowed' => $hour->max_allowed
                ]);
                continue;
            }

            $maxSlotsPerHour = $day->number_per_hour;

            // Lock and count existing appointments for this slot (backup check)
            $appointmentCount = Appointment::lockForUpdate()
                ->where('event_specialty_id', $specialtyData['event_specialty_id'])
                ->where('day_id', $specialtyData['day_id'])
                ->where('hour_id', $specialtyData['hour_id'])
                ->count();

            // Check if slot is available
            if ($appointmentCount >= $maxSlotsPerHour) {
                $waitingListSpecialties[] = $eventSpecialty->specialty->name;
                Log::info('Slot full:', [
                    'event_specialty_id' => $specialtyData['event_specialty_id'],
                    'day_id' => $specialtyData['day_id'],
                    'hour_id' => $specialtyData['hour_id'],
                    'count' => $appointmentCount,
                    'max' => $maxSlotsPerHour
                ]);
                continue;
            }

            // Get doctor for this event specialty
            $doctor = $eventSpecialty->doctors()->first();

            // Create appointment
            $appointment = Appointment::create([
                'user_id' => 1,
                'event_id' => $eventID,
                'event_specialty_id' => $specialtyData['event_specialty_id'],
                'day_id' => $specialtyData['day_id'],
                'hour_id' => $specialtyData['hour_id'],
                'doctor_id' => $doctor ? $doctor->id : null,
                'full_name' => $fullName,
                'birthday' => $validatedData['birthday'],
                'state' => $validatedData['state'],
                'city' => $validatedData['city'],
                'diseases' => $validatedData['diseases'] ?? 'nothing',
                'phone' => $validatedData['phone'],
                'phone2' => $validatedData['phone2'],
                'sex' => $validatedData['sex'],
                'patient_id' => $nextPatientId,
                'specialty_order' => $index === 0 ? 1 : 0,
                'status' => 'Pending',
                'position' => $appointmentCount + 1,
                'orderList' => 0,
            ]);

            // ✅ INCREMENT HOUR COUNTER
            $hour->increment('counter');

            Log::info('Appointment created and counter incremented:', [
                'appointment_id' => $appointment->id,
                'patient_id' => $nextPatientId,
                'hour_id' => $hour->id,
                'new_counter' => $hour->counter,
                'max_allowed' => $hour->max_allowed
            ]);

            $appointments[] = $appointment->load(['eventSpecialty.specialty', 'day', 'hour']);
        }

        // Check if at least one appointment was created
        if (empty($appointments)) {
            DB::rollBack();
            return response()->json([
                'message' => 'جميع المواعيد المطلوبة ممتلئة.',
                'waiting_list_specialties' => $waitingListSpecialties
            ], 409);
        }

        DB::commit();

        $message = 'تم حجز موعدك بنجاح!' ;
        $waitingMessage = '';

        if (!empty($waitingListSpecialties)) {
            $waitingMessage = 'تنبيه: التخصصات التالية كانت ممتلئة ولم يتم حجزها: ' . implode(', ', $waitingListSpecialties);
        }

        return response()->json([
            'message' => $message,
            'waiting_message' => $waitingMessage,
            'appointments' => $appointments,
            'patient_id' => $nextPatientId,
            'event_id' => $event->id,
            'event_name' => $event->name,
        ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Appointment creation error:', [
                'event_id' => $eventID,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'حدث خطأ أثناء حجز الموعد: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }

    /**
     * Display appointments for a specific event
     */
    public function index($eventID)
    {
        $event = Event::find($eventID);

        if (!$event) {
            return response()->json(['message' => 'الحدث غير موجود.'], 404);
        }

        $appointments = Appointment::with([
                'eventSpecialty.specialty',
                'day',
                'hour',
                'doctor'
            ])
            ->whereHas('eventSpecialty', function($query) use ($event) {
                $query->where('event_id', $event->id);
            })
            ->orderBy('patient_id')
            ->orderBy('specialty_order', 'desc')
            ->get()
            ->groupBy('patient_id');

        return response()->json([
            'message' => 'تم جلب المواعيد بنجاح.',
            'event' => $event,
            'appointments' => $appointments
        ], 200);
    }


    /**
     * Mark patient as special case - priority position
     */
    public function SpecialCase($id)
    {
        $user_role = Auth::user()->role_id;

        if (!in_array($user_role, [1, 3])) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        // Get all appointments for this patient
        $appointments = Appointment::where('patient_id', $id)->get();

        if ($appointments->isEmpty()) {
            return response()->json(['message' => 'No appointments found for this patient'], 404);
        }

        $i = 0;
        foreach ($appointments as $appointment) {
            // First appointment is Present with priority, others are Waiting
            if ($i === 0) {
                $appointment->status = 'Present';
            } else {
                $appointment->status = 'Waiting';
            }

            // Priority position (0 = first in line)
            $appointment->position = 0;
            
            // Mark as special case in full_name
            if (!str_contains($appointment->full_name, '(حالة خاصة)')) {
                $appointment->full_name = $appointment->full_name . ' (حالة خاصة)';
            }
            
            $appointment->save();
            $i++;
        }

        return response()->json([
            'message' => 'We added the Special Case to Position 1',
            'appointment' => $appointments->first()
        ], 200);
    }

    /**
     * Add comment to appointment
     */
    public function addComment(Request $request)
    {
        $validated = $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'comment' => 'required|string|max:1000'
        ]);

        $appointment = Appointment::find($validated['appointment_id']);
        $appointment->comment = $validated['comment'];
        $appointment->save();

        return response()->json([
            'message' => 'تم إضافة التعليق بنجاح',
            'appointment' => $appointment
        ], 200);
    }

    /**
     * Delete appointment
     */
public function destroy(Appointment $appointment)
{
    DB::beginTransaction();
    try {
        // ✅ Decrement hour counter before deleting
        $hour = Hour::find($appointment->hour_id);
        if ($hour && $hour->counter > 0) {
            $hour->decrement('counter');
            
            Log::info('Hour counter decremented:', [
                'hour_id' => $hour->id,
                'new_counter' => $hour->counter,
                'appointment_id' => $appointment->id
            ]);
        }

        $appointment->delete();

        DB::commit();

        return response()->json([
            'message' => 'تم حذف الموعد بنجاح'
        ], 200);
        
    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Delete appointment error:', [
            'appointment_id' => $appointment->id,
            'error' => $e->getMessage()
        ]);
        
        return response()->json([
            'message' => 'حدث خطأ أثناء حذف الموعد'
        ], 500);
    }
}

    /**
     * Add multiple appointments (legacy method)
     */
    public function addMultiple(Request $request)
    {
        // Redirect to storeMultiple
        return $this->storeMultiple($request);
    }

    /**
     * Delete duplicate appointments
     */
    public function deleteDuplicates()
    {
        try {
            DB::beginTransaction();

            // Find duplicates based on full_name, birthday, and event
            $duplicates = Appointment::select('full_name', 'birthday', 'event_specialty_id', DB::raw('COUNT(*) as count'))
                ->groupBy('full_name', 'birthday', 'event_specialty_id')
                ->having('count', '>', 1)
                ->get();

            $deletedCount = 0;

            foreach ($duplicates as $duplicate) {
                // Keep the first appointment, delete the rest
                $appointments = Appointment::where('full_name', $duplicate->full_name)
                    ->where('birthday', $duplicate->birthday)
                    ->where('event_specialty_id', $duplicate->event_specialty_id)
                    ->orderBy('id')
                    ->get();

                // Skip the first one, delete the rest
                foreach ($appointments->skip(1) as $appointment) {
                    $appointment->delete();
                    $deletedCount++;
                }
            }

            DB::commit();

            return response()->json([
                'message' => "تم حذف {$deletedCount} موعد مكرر",
                'deleted_count' => $deletedCount
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete duplicates error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'حدث خطأ أثناء حذف المواعيد المكررة',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }
}