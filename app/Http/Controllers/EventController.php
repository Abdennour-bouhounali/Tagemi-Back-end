<?php

namespace App\Http\Controllers;



use App\Models\Appointment;  // ADD THIS
use App\Models\AppointmentArchive;  // ADD THIS
use Illuminate\Support\Facades\Auth;
use App\Models\Event;
use App\Models\EventSpecialty;
use App\Models\Specialty;
use App\Models\User;
use App\Support\VisitStatistics;
use App\Exceptions\RuleException;
use App\Models\Day;
use App\Models\Hour;
use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

use Carbon\Carbon;
class EventController extends Controller
{



    

    /**
     * Get event with statistics
     * Optional: Enhanced version that includes stats
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function showWithStats($id)
    {
        $event = Event::with('eventSpecialties.specialty')->findOrFail($id);

        return response()->json([
            'event' => $event,
            'statistics' => VisitStatistics::summarize($event->appointments()->get(['status', 'patient_id']))
                + ['specialties_count' => $event->eventSpecialties->count()],
        ], 200);
    }

    /**
     * Get admin credentials for an event
     */
    public function getAdminCredentials($eventId)
    {
        try {
            $event = Event::with([
                'eventSpecialties',
                'specialAdmin',
                'recipientAdmin',
                'checkAdmin'
            ])->find($eventId);

            if (!$event) {
                return response()->json(['message' => 'الحدث غير موجود'], 404);
            }

            $credentials = [];
            $processedAdminIds = [];

            // Add Special Admin (from event)
            if ($event->specialAdmin && !in_array($event->specialAdmin->id, $processedAdminIds)) {
                $credentials[] = [
                    'type' => 'Special Admin',
                    'specialty' => 'All Specialties',
                    'name' => $event->specialAdmin->name,
                    'email' => $event->specialAdmin->email,
                    'password' => '********',
                ];
                $processedAdminIds[] = $event->specialAdmin->id;
            }

            // Add Recipient Admin (from event)
            if ($event->recipientAdmin && !in_array($event->recipientAdmin->id, $processedAdminIds)) {
                $credentials[] = [
                    'type' => 'Recipient Admin',
                    'specialty' => 'All Specialties',
                    'name' => $event->recipientAdmin->name,
                    'email' => $event->recipientAdmin->email,
                    'password' => '********',
                ];
                $processedAdminIds[] = $event->recipientAdmin->id;
            }

            // Add Check Admin (from event)
            if ($event->checkAdmin && !in_array($event->checkAdmin->id, $processedAdminIds)) {
                $credentials[] = [
                    'type' => 'Check Admin',
                    'specialty' => 'All Specialties',
                    'name' => $event->checkAdmin->name,
                    'email' => $event->checkAdmin->email,
                    'password' => '********',
                ];
                $processedAdminIds[] = $event->checkAdmin->id;
            }

            return response()->json([
                'credentials' => $credentials,
                'note' => 'لأسباب أمنية، لا يمكن عرض كلمات المرور المخزنة. يرجى استخدام وظيفة إعادة تعيين كلمة المرور إذا لزم الأمر.'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to get admin credentials', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء جلب بيانات المسؤولين',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }

    /**
     * Reset passwords for all admins in an event
     */
    public function resetEventAdminPasswords(Request $request, $eventId)
    {
        $validator = Validator::make($request->all(), [
            'new_password' => 'required|string|min:6',
        ], [
            'new_password.required' => 'كلمة المرور الجديدة مطلوبة',
            'new_password.min' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'فشل التحقق من البيانات',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $event = Event::find($eventId);

            if (!$event) {
                return response()->json(['message' => 'الحدث غير موجود'], 404);
            }

            $hashedPassword = Hash::make($request->new_password);
            $updatedAdminsCount = 0;
            $updatedAdminIds = [];

            // Update Special Admin (from event)
            if ($event->special_admin_id && !in_array($event->special_admin_id, $updatedAdminIds)) {
                User::where('id', $event->special_admin_id)
                    ->where('role_id', '!=', User::ROLE_SUPER_ADMIN)
                    ->update(['password' => $hashedPassword]);
                $updatedAdminIds[] = $event->special_admin_id;
                $updatedAdminsCount++;
            }

            // Update Recipient Admin (from event)
            if ($event->recipient_admin_id && !in_array($event->recipient_admin_id, $updatedAdminIds)) {
                User::where('id', $event->recipient_admin_id)
                    ->where('role_id', '!=', User::ROLE_SUPER_ADMIN)
                    ->update(['password' => $hashedPassword]);
                $updatedAdminIds[] = $event->recipient_admin_id;
                $updatedAdminsCount++;
            }

            // Update Check Admin (from event)
            if ($event->check_admin_id && !in_array($event->check_admin_id, $updatedAdminIds)) {
                User::where('id', $event->check_admin_id)
                    ->where('role_id', '!=', User::ROLE_SUPER_ADMIN)
                    ->update(['password' => $hashedPassword]);
                $updatedAdminIds[] = $event->check_admin_id;
                $updatedAdminsCount++;
            }

            // Log the old sessions out everywhere
            \Laravel\Sanctum\PersonalAccessToken::where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $updatedAdminIds)
                ->delete();

            return response()->json([
                'message' => 'تم تحديث كلمات المرور بنجاح',
                'updated_admins_count' => $updatedAdminsCount
            ], 200);

        } catch (\Exception $e) {
            Log::error('Password reset failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء تحديث كلمات المرور',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }

    /**
     * Delete all admins associated with an event
     */
    public function deleteEventAdmins($eventId)
    {
        DB::beginTransaction();
        try {
            $event = Event::find($eventId);

            if (!$event) {
                return response()->json(['message' => 'الحدث غير موجود'], 404);
            }

            $deletedAdminsCount = 0;
            $adminIdsToDelete = [];

            // Collect Special Admin ID (from event)
            if ($event->special_admin_id && !in_array($event->special_admin_id, $adminIdsToDelete)) {
                $adminIdsToDelete[] = $event->special_admin_id;
            }

            // Collect Recipient Admin ID (from event)
            if ($event->recipient_admin_id && !in_array($event->recipient_admin_id, $adminIdsToDelete)) {
                $adminIdsToDelete[] = $event->recipient_admin_id;
            }

            // Collect Check Admin ID (from event)
            if ($event->check_admin_id && !in_array($event->check_admin_id, $adminIdsToDelete)) {
                $adminIdsToDelete[] = $event->check_admin_id;
            }

            // Delete all collected admin users
            if (!empty($adminIdsToDelete)) {
                $deletedAdminsCount = User::whereIn('id', $adminIdsToDelete)->delete();
            }

            // Clear admin references in event
            $event->update([
                'special_admin_id' => null,
                'recipient_admin_id' => null,
                'check_admin_id' => null,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'تم حذف جميع المسؤولين بنجاح',
                'deleted_admins_count' => $deletedAdminsCount
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Admin deletion failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء حذف المسؤولين',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }


    public function getSpecialties($id)
    {
        $event = Event::with('eventSpecialties.specialty')->findOrFail($id);
        return response()->json([
            'event_specialties' => $event->eventSpecialties
        ]);
    }
    public function getDays($id)
{
    $eventSpecialty = EventSpecialty::with('days')->findOrFail($id);
    return response()->json([
        'days' => $eventSpecialty->days
    ]);
}
public function getHours($id)
{
    $day = Day::with('hours')->findOrFail($id);
    return response()->json([
        'hours' => $day->hours
    ]);
}

    /**
     * Get current event
     */
    public function getCurrentEvent()
    {
        $event = Event::with([
            'eventSpecialties.specialty.checkAdmin',
            'specialAdmin',
            'recipientAdmin',
            'checkAdmin',
            'eventSpecialties.doctors',
            'eventSpecialties.days.hours'
        ])->where('is_active', true)
          ->where('is_archived', false)
          ->first();

        if (!$event) {
            return response()->json(['message' => 'لا يوجد حدث نشط حالياً'], 404);
        }

        return response()->json(['event' => $event], 200);
    }




/**
 * Get detailed appointment distribution for an event
 * Shows bookings by specialty, day, and hour
 * 
 * @param int $id - Event ID
 * @return \Illuminate\Http\JsonResponse
 */
public function getAppointmentDistribution($id)
{
    try {
        $event = Event::with([
            'eventSpecialties.specialty',
            'eventSpecialties.days.hours'
        ])->findOrFail($id);

        $distribution = [];

        foreach ($event->eventSpecialties as $eventSpecialty) {
            $specialtyData = [
                'event_specialty_id' => $eventSpecialty->id,
                'specialty_id' => $eventSpecialty->specialty_id,
                'specialty_name' => $eventSpecialty->specialty->name,
                'is_saturated' => $eventSpecialty->is_saturated,
                'total_appointments' => 0,
                'total_capacity' => 0,
                'days' => []
            ];

            foreach ($eventSpecialty->days as $day) {
                $dayData = [
                    'day_id' => $day->id,
                    'day_date' => $day->day_date,
                    'day_name' => \Carbon\Carbon::parse($day->day_date)->locale('ar')->isoFormat('dddd'),
                    'formatted_date' => \Carbon\Carbon::parse($day->day_date)->locale('ar')->isoFormat('D MMMM YYYY'),
                    'number_per_hour' => $day->number_per_hour,
                    'total_appointments' => 0,
                    'total_capacity' => 0,
                    'hours' => []
                ];

                foreach ($day->hours as $hour) {
                    $hourData = [
                        'hour_id' => $hour->id,
                        'time' => $hour->time,
                        'formatted_time' => \Carbon\Carbon::parse($hour->time)->format('H:i'),
                        'max_allowed' => $hour->max_allowed,
                        'counter' => $hour->counter,
                        'available' => max(0, $hour->max_allowed - $hour->counter),
                        'percentage_full' => $hour->max_allowed > 0 
                            ? round(($hour->counter / $hour->max_allowed) * 100, 2) 
                            : 0,
                        'is_full' => $hour->counter >= $hour->max_allowed,
                        'status' => $this->getHourStatus($hour->counter, $hour->max_allowed)
                    ];

                    $dayData['hours'][] = $hourData;
                    $dayData['total_appointments'] += $hour->counter;
                    $dayData['total_capacity'] += $hour->max_allowed;
                }

                $dayData['percentage_full'] = $dayData['total_capacity'] > 0
                    ? round(($dayData['total_appointments'] / $dayData['total_capacity']) * 100, 2)
                    : 0;

                $specialtyData['days'][] = $dayData;
                $specialtyData['total_appointments'] += $dayData['total_appointments'];
                $specialtyData['total_capacity'] += $dayData['total_capacity'];
            }

            $specialtyData['percentage_full'] = $specialtyData['total_capacity'] > 0
                ? round(($specialtyData['total_appointments'] / $specialtyData['total_capacity']) * 100, 2)
                : 0;

            $distribution[] = $specialtyData;
        }

        // Count total unique patients for this event
        $totalPatients = \App\Models\Appointment::where('event_id', $id)
            ->whereNotNull('patient_id')
            ->distinct()
            ->count('patient_id');

        // Overall event statistics
        $eventStatistics = [
            'total_appointments' => array_sum(array_column($distribution, 'total_appointments')),
            'total_capacity' => array_sum(array_column($distribution, 'total_capacity')),
            'total_available' => array_sum(array_column($distribution, 'total_capacity')) - array_sum(array_column($distribution, 'total_appointments')),
            'total_patients' => $totalPatients,
            'percentage_full' => 0
        ];

        if ($eventStatistics['total_capacity'] > 0) {
            $eventStatistics['percentage_full'] = round(
                ($eventStatistics['total_appointments'] / $eventStatistics['total_capacity']) * 100, 
                2
            );
        }

        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'date' => $event->date,
                'place' => $event->place,
                'is_current' => $event->is_current,
                'is_archived' => $event->is_archived,
            ],
            'statistics' => $eventStatistics,
            'distribution' => $distribution
        ], 200);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'message' => 'الحدث غير موجود'
        ], 404);
    } catch (\Exception $e) {
        Log::error('Get appointment distribution error:', [
            'event_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'message' => 'حدث خطأ أثناء جلب توزيع المواعيد',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
        ], 500);
    }
}
/**
 * Helper method to determine hour status
 */
private function getHourStatus($counter, $maxAllowed)
{
    if ($counter >= $maxAllowed) {
        return 'full';
    } elseif ($counter >= $maxAllowed * 0.8) {
        return 'almost_full';
    } elseif ($counter >= $maxAllowed * 0.5) {
        return 'half_full';
    } elseif ($counter > 0) {
        return 'available';
    } else {
        return 'empty';
    }
}
public function toggleSaturation($eventId, $eventSpecialtyId)
{
    try {
        $eventSpecialty = EventSpecialty::where('event_id', $eventId)
            ->where('id', $eventSpecialtyId)
            ->firstOrFail();
        
        // Toggle the saturation status
        $eventSpecialty->is_saturated = !$eventSpecialty->is_saturated;
        $eventSpecialty->save();
        
        return response()->json([
            'message' => $eventSpecialty->is_saturated 
                ? 'تم تعيين التخصص كمشبع' 
                : 'تم إلغاء إشباع التخصص',
            'event_specialty' => $eventSpecialty->load('specialty')
        ], 200);
        
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'message' => 'التخصص غير موجود'
        ], 404);
    } catch (\Exception $e) {
        Log::error('Failed to toggle saturation', [
            'event_id' => $eventId,
            'event_specialty_id' => $eventSpecialtyId,
            'error' => $e->getMessage()
        ]);
        
        return response()->json([
            'message' => 'حدث خطأ أثناء تحديث حالة الإشباع',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
        ], 500);
    }
}

/**
 * Update saturation status for multiple specialties at once
 * 
 * @param Request $request
 * @param int $eventId
 * @return \Illuminate\Http\JsonResponse
 */
public function updateSaturationBatch(Request $request, $eventId)
{
    $validator = Validator::make($request->all(), [
        'specialties' => 'required|array|min:1',
        'specialties.*.id' => 'required|integer|exists:event_specialties,id',
        'specialties.*.is_saturated' => 'required|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'فشل التحقق من البيانات',
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();
    try {
        $event = Event::findOrFail($eventId);
        $updatedCount = 0;
        
        foreach ($request->specialties as $specData) {
            $eventSpecialty = EventSpecialty::where('event_id', $eventId)
                ->where('id', $specData['id'])
                ->first();
                
            if ($eventSpecialty) {
                $eventSpecialty->is_saturated = $specData['is_saturated'];
                $eventSpecialty->save();
                $updatedCount++;
            }
        }
        
        DB::commit();
        
        return response()->json([
            'message' => "تم تحديث حالة الإشباع لـ {$updatedCount} تخصص",
            'updated_count' => $updatedCount
        ], 200);
        
    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Batch saturation update failed', [
            'event_id' => $eventId,
            'error' => $e->getMessage()
        ]);
        
        return response()->json([
            'message' => 'حدث خطأ أثناء تحديث حالات الإشباع',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
        ], 500);
    }
}
    // Add specialty to event
    public function addSpecialty(Request $request, $eventId)
    {
        $event = Event::findOrFail($eventId);
        
        $validated = $request->validate([
            'specialty_id' => 'required|exists:specialties,id',
            'day' => 'required|string|max:50',
            'hours' => 'required|string|max:100',
        ]);
        
        // Check if specialty already exists for this event
        $exists = EventSpecialty::where('event_id', $eventId)
            ->where('specialty_id', $validated['specialty_id'])
            ->exists();
            
        if ($exists) {
            return response()->json([
                'message' => 'هذا التخصص موجود بالفعل لهذا الحدث'
            ], 422);
        }
        
        $eventSpecialty = EventSpecialty::create([
            'event_id' => $eventId,
            'specialty_id' => $validated['specialty_id'],
            'day' => $validated['day'],
            'hours' => $validated['hours'],
        ]);
        
        return response()->json([
            'message' => 'Specialty added successfully',
            'specialty' => $eventSpecialty->load('specialty:id,name')
        ], 201);
    }
    
    // Update event specialty
    public function updateSpecialty(Request $request, $eventId, $specialtyId)
    {
        $eventSpecialty = EventSpecialty::where('event_id', $eventId)
            ->where('id', $specialtyId)
            ->firstOrFail();
        
        $validated = $request->validate([
            'specialty_id' => 'required|exists:specialties,id',
            'day' => 'required|string|max:50',
            'hours' => 'required|string|max:100',
        ]);
        
        // Check if another record has the same specialty_id for this event
        $exists = EventSpecialty::where('event_id', $eventId)
            ->where('specialty_id', $validated['specialty_id'])
            ->where('id', '!=', $specialtyId)
            ->exists();
            
        if ($exists) {
            return response()->json([
                'message' => 'هذا التخصص موجود بالفعل لهذا الحدث'
            ], 422);
        }
        
        $eventSpecialty->update($validated);
        
        return response()->json([
            'message' => 'Specialty updated successfully',
            'specialty' => $eventSpecialty->load('specialty:id,name')
        ]);
    }
    // Get event specialties
    public function getEventSpecialties($eventId)
    {
        $event = Event::findOrFail($eventId);
        
        $specialties = EventSpecialty::where('event_id', $eventId)
            ->with('specialty:id,name')
            ->get()
            ->map(function ($eventSpecialty) {
                return [
                    'id' => $eventSpecialty->id,
                    'specialty_id' => $eventSpecialty->specialty_id,
                    'specialty_name' => $eventSpecialty->specialty->name ?? 'N/A',
                    'day' => $eventSpecialty->day,
                    'hours' => $eventSpecialty->hours,
                ];
            });
        
        return response()->json([
            'specialties' => $specialties,
            'event' => $event
        ]);
    }
    
    // Delete event specialty
    public function deleteSpecialty($eventId, $specialtyId)
    {
        $eventSpecialty = EventSpecialty::where('event_id', $eventId)
            ->where('id', $specialtyId)
            ->firstOrFail();

        $this->refuseIfBooked($eventSpecialty->appointments(), 'لا يمكن حذف تخصص به مواعيد محجوزة');
        $eventSpecialty->delete();
        
        return response()->json([
            'message' => 'Specialty deleted successfully'
        ]);
    }
    /**
     * Set an event as current
     */
    public function setCurrent($id)
    {
        $user_role = Auth::user()->role_id ?? null;
                
        if (!in_array($user_role, [1, 3])) {
            return response()->json([
                'message' => "You are not authorized"
            ], 403);
        }

        Event::query()->update(['is_active' => 0]);
        
        $event = Event::find($id);
        
        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        $event->is_current = 1;
        $event->save();

        return response()->json([
            'message' => 'Event set as current successfully',
            'event' => $event
        ], 200);
    }


    public function statistics($id)
    {
        $event = Event::findOrFail($id);
        $summary = VisitStatistics::summarize($event->appointments()->get(['status', 'patient_id']));

        return response()->json($summary + [
            'total_appointments' => $summary['total'],
            'is_archived' => (bool) $event->is_archived,
            'is_active' => (bool) $event->is_active,
        ]);
    }
   
    /**
     * Get all events (for dropdown in navbar)
     */
public function index()
{
    try {
        $user = request()->user();

        $events = Event::query()
            ->when(!$user->isSuperAdmin(), fn ($q) => $q->whereKey($user->event_id))
            ->withCount('eventSpecialties')
            ->with(['eventSpecialties.days' => function($query) {
                $query->select('event_specialty_id', 'day_date');
            }])
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($event) {
                // Get all day_dates from all event specialties
                $allDayDates = $event->eventSpecialties
                    ->flatMap(fn($specialty) => $specialty->days->pluck('day_date'))
                    ->filter();

                if ($allDayDates->isNotEmpty()) {
                    $event->start_date = $allDayDates->min();
                    $event->end_date = $allDayDates->max();
                } else {
                    // Fallback to event date if no days
                    $event->start_date = $event->date;
                    $event->end_date = $event->date;
                }

                // Clean up the response
                unset($event->eventSpecialties);

                return $event;
            });
        
        return response()->json([
            'events' => $events
        ], 200);
    } catch (\Exception $e) {
        Log::error('Error fetching events:', ['error' => $e->getMessage()]);
        return response()->json([
            'message' => 'فشل في تحميل الأحداث',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
        ], 500);
    }
}

    /**
     * Public event details for the booking form and the waiting-room screen.
     * Contains no patient data and hides archived events.
     */
    public function publicShow($id)
    {
        $event = Event::with([
            'eventSpecialties.specialty',
            'eventSpecialties.days.hours'
        ])
            ->where('is_archived', false)
            ->find($id);

        if (!$event) {
            return response()->json(['message' => 'الحدث غير موجود'], 404);
        }

        $event->makeHidden(['special_admin_id', 'recipient_admin_id', 'check_admin_id']);

        return response()->json(['event' => $event], 200);
    }

    /**
     * Get single event details
     */
    public function show($id)
    {
        try {
            // Only appointment counts here: patient rows are served by the
            // event-scoped appointment endpoints, never with the event payload.
            $event = Event::with([
                'eventSpecialties' => fn ($q) => $q->withCount('appointments'),
                'eventSpecialties.specialty',
                'eventSpecialties.days.hours'
            ])->findOrFail($id);

            return response()->json([
                'event' => $event
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'الحدث غير موجود'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching event:', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'فشل في تحميل تفاصيل الحدث',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Get all active events
     */
    public function getActiveEvents()
    {
        try {
            $events = Event::with([
                'eventSpecialties.specialty.checkAdmin',
                'specialAdmin',
                'recipientAdmin',
                'checkAdmin',
                'eventSpecialties.doctors',
                'eventSpecialties.days.hours'
            ])->where('is_active', true)
              ->where('is_archived', false)
              ->orderBy('date', 'asc')
              ->get();

            if ($events->isEmpty()) {
                return response()->json(['message' => 'لا يوجد أحداث نشطة حالياً'], 404);
            }

            return response()->json(['events' => $events], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching active events:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'فشل في تحميل الأحداث النشطة',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Get active TAWAT events
     */
    public function getActiveTawatEvents()
    {
        try {
            $events = Event::with([
                'eventSpecialties.specialty',
                'eventSpecialties.doctors',
                'eventSpecialties.days.hours'
            ])->where('is_active', true)
              ->where('is_tawat', true)
              ->where('is_archived', false)
              ->orderBy('date', 'asc')
              ->get();

            return response()->json(['events' => $events], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching active TAWAT events:', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'فشل في تحميل أحداث TAWAT النشطة',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Helper method to generate unique email
     */
    private function generateUniqueEmail($prefix, $suffix)
    {
        $baseEmail = strtolower($prefix . '.' . $suffix . '@system.com');
        $counter = 1;
        $email = $baseEmail;
        
        while (User::where('email', $email)->exists()) {
            $email = strtolower($prefix . '.' . $suffix . $counter . '@system.com');
            $counter++;
        }
        
        return $email;
    }

    /**
     * Helper method to generate random password
     */
    private function generateRandomPassword($length = 12)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $password;
    }

    /**
     * Store a new event with automatic admin creation
     */
   public function store(Request $request)
{
            $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:255',
        'date' => 'required|date',
        'state' => 'nullable|string|max:255',
        'city' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'nbr_of_allowed_specialties' => 'nullable|integer|min:1|max:5', // ADD THIS
        'is_active' => 'nullable|boolean',
        'is_tawat' => 'nullable|boolean',
        'rules_ids' => 'nullable|array', // ADD THIS
        'rules_ids.*' => 'integer|exists:rules,id', // ADD THIS
        'specialties' => 'required|array|min:1',
        'specialties.*.id' => 'required|exists:specialties,id',
        'specialties.*.description' => 'nullable|string|max:500',
        'specialties.*.doctors' => 'nullable|array',
        'specialties.*.doctors.*' => 'exists:doctors,id',
        'specialties.*.is_saturated' => 'nullable|boolean',
        'specialties.*.has_dilatation' => 'nullable|boolean',
        'specialties.*.days' => 'required|array|min:1',
        'specialties.*.days.*.day_date' => 'required|date',
        'specialties.*.days.*.number_per_hour' => 'required|integer|min:1|max:400',
        'specialties.*.days.*.hours' => 'required|array|min:1',
        'specialties.*.days.*.hours.*.time' => 'required|date_format:H:i:s',
    ]);


    if ($validator->fails()) {
        return response()->json([
            'message' => 'فشل التحقق من البيانات',
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();
    try {
        $createdAdmins = [];

        // Create event
    $event = Event::create([
        'name' => $request->name,
        'date' => $request->date,
        'state' => $request->state,
        'city' => $request->city,
        'description' => $request->description,
        'nbr_of_allowed_specialties' => $request->nbr_of_allowed_specialties ?? 1,
        'rules_ids' => $request->rules_ids ?? [],
        'is_active' => $request->is_active ?? true,
        'is_tawat' => $request->is_tawat ?? true,
        'is_archived' => false,
        'special_admin_id' => null,
        'recipient_admin_id' => null,
        'check_admin_id' => null,
    ]);

        // Process each specialty
        foreach ($request->specialties as $specData) {
            $isSaturated = $specData['is_saturated'] ?? false;

            $eventSpecialty = EventSpecialty::create([
                    'event_id' => $event->id,
                    'specialty_id' => $specData['id'],
                    'description' => $specData['description'] ?? null, // ADD THIS
                    'is_saturated' => $isSaturated,
                    'has_dilatation' => (bool) ($specData['has_dilatation'] ?? false),
                    'max_number' => 100,
                    'start_time' => $specData['start_time'] ?? '08:00:00',
                    'addition_capacity' => 0,
                    'flag' => 'Open',
                    'is_active' => true,
                ]);

            if (!empty($specData['doctors'])) {
                $eventSpecialty->doctors()->attach($specData['doctors']);
            }

            // Process days
            foreach ($specData['days'] as $dayData) {
                $numberPerHour = $dayData['number_per_hour'] ?? 6;
                
                $day = Day::create([
                    'event_specialty_id' => $eventSpecialty->id,
                    'day_date' => $dayData['day_date'],
                    'number_per_hour' => $numberPerHour,
                ]);

                // Process hours
                $hoursToInsert = [];
                foreach ($dayData['hours'] as $hourData) {
                    if (!in_array($hourData['time'], array_column($hoursToInsert, 'time'))) {
                        $hoursToInsert[] = [
                            'day_id' => $day->id,
                            'time' => $hourData['time'],
                            'max_allowed' => $numberPerHour,
                            'counter' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
                
                if (!empty($hoursToInsert)) {
                    Hour::insert($hoursToInsert);
                }
            }
        }

        DB::commit();

        $event->load([
            'eventSpecialties.specialty',
            'eventSpecialties.doctors',
            'specialAdmin',
            'recipientAdmin',
            'checkAdmin',
        ]);

        return response()->json([
            'message' => 'تم إنشاء الحدث بنجاح',
            'event' => $event,
            'summary' => [
                'total_specialties' => count($request->specialties),
                'special_admins_created' => 1,
                'recipient_admins_created' => 1,
                'check_admins_created' => 1,
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Event creation failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request_keys' => array_keys($request->all())
        ]);

        return response()->json([
            'message' => 'حدث خطأ أثناء إنشاء الحدث',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
        ], 500);
    }
}
    /**
     * Update basic event information
     */
    public function update(Request $request, $id)
    {
        $event = Event::findOrFail($id);
    
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'date' => 'required|date',
        'state' => 'nullable|string|max:255',
        'city' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'nbr_of_allowed_specialties' => 'nullable|integer|min:1|max:5',
        'rules_ids' => 'nullable|array', // ADD THIS
        'rules_ids.*' => 'integer|exists:rules,id', // ADD THIS
        'is_active' => 'nullable|boolean',
        'is_tawat' => 'nullable|boolean',
    ]);
        
        $event->update($validated);
        
    return response()->json([
        'message' => 'تم تحديث الحدث بنجاح',
        'event' => $event->load('rules') // Load rules relationship
    ]);
    }

/**
 * Event editing never deletes bookings: removing a slot, day or specialty
 * that still has appointments is refused (move or delete them first).
 *
 * @throws RuleException
 */
private function refuseIfBooked($appointmentsQuery, string $message): void
{
    $count = $appointmentsQuery->count();
    if ($count > 0) {
        throw new RuleException($message . " ({$count} موعد). انقل المواعيد أو احذفها أولاً.", 409);
    }
}

 /**
 * Update full event with specialties, days, and hours
 * 
 * This method handles:
 * - Updating existing items (with IDs)
 * - Creating new items (without IDs or null IDs)
 * - Deleting items not in the payload
 */
public function updateFull(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:255',
        'date' => 'required|date',
        'state' => 'nullable|string|max:255',
        'city' => 'nullable|string|max:255',
        'description' => 'nullable|string',
        'is_active' => 'nullable|boolean',
        'is_tawat' => 'nullable|boolean',
        'nbr_of_allowed_specialties' => 'nullable|integer|min:1|max:5',
        'rules_ids' => 'nullable|array',
        'rules_ids.*' => 'integer|exists:rules,id',
        'specialties' => 'nullable|array',
        'specialties.*.id' => 'required|exists:specialties,id',
        'specialties.*.eventSpecialtyId' => 'nullable|integer',
        'specialties.*.description' => 'nullable|string|max:500',
        'specialties.*.doctors' => 'nullable|array',
        'specialties.*.doctors.*' => 'exists:doctors,id',
        'specialties.*.is_saturated' => 'nullable|boolean',
        'specialties.*.has_dilatation' => 'nullable|boolean',
        'specialties.*.days' => 'required|array|min:1',
        'specialties.*.days.*.id' => 'nullable|integer',
        'specialties.*.days.*.day_date' => 'required|date',
        'specialties.*.days.*.number_per_hour' => 'required|integer|min:1|max:400',
        'specialties.*.days.*.hours' => 'required|array|min:1',
        'specialties.*.days.*.hours.*.id' => 'nullable|integer',
        'specialties.*.days.*.hours.*.time' => 'required|date_format:H:i:s',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'فشل التحقق من البيانات',
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();
    try {
        $event = Event::findOrFail($id);
        
        // Update basic event information
        $event->update([
            'name' => $request->name,
            'date' => $request->date,
            'state' => $request->state,
            'city' => $request->city,
            'description' => $request->description,
            'nbr_of_allowed_specialties' => $request->nbr_of_allowed_specialties ?? $event->nbr_of_allowed_specialties,
            'rules_ids' => $request->rules_ids ?? [],
            'is_active' => $request->is_active ?? $event->is_active,
            'is_tawat' => $request->is_tawat ?? $event->is_tawat,
        ]);

        if ($request->has('specialties')) {
            $processedEventSpecialtyIds = [];
            
            foreach ($request->specialties as $specData) {
                $isSaturated = $specData['is_saturated'] ?? false;
                
                // Check if this is an existing event_specialty or a new one
                if (!empty($specData['eventSpecialtyId'])) {
                    // UPDATE EXISTING EVENT_SPECIALTY
                    $eventSpecialty = EventSpecialty::where('id', $specData['eventSpecialtyId'])
                        ->where('event_id', $event->id)
                        ->first();
                    
                    if ($eventSpecialty) {
                        $eventSpecialty->update([
                            'specialty_id' => $specData['id'],
                            'description' => $specData['description'] ?? '',
                            'is_saturated' => $isSaturated,
                            'has_dilatation' => (bool) ($specData['has_dilatation'] ?? $eventSpecialty->has_dilatation),
                        ]);
                        
                        Log::info("Updated EventSpecialty ID: {$eventSpecialty->id}");
                    } else {
                        Log::warning("EventSpecialty ID {$specData['eventSpecialtyId']} not found, creating new one");
                        $eventSpecialty = $this->createEventSpecialty($event->id, $specData, $isSaturated);
                    }
                } else {
                    // CREATE NEW EVENT_SPECIALTY
                    $eventSpecialty = $this->createEventSpecialty($event->id, $specData, $isSaturated);
                    Log::info("Created new EventSpecialty ID: {$eventSpecialty->id}");
                }
                
                $processedEventSpecialtyIds[] = $eventSpecialty->id;
                
                // Sync doctors
                if (isset($specData['doctors'])) {
                    $eventSpecialty->doctors()->sync($specData['doctors']);
                }
                
                // Process Days
                $processedDayIds = [];
                
                foreach ($specData['days'] as $dayData) {
                    $numberPerHour = $dayData['number_per_hour'] ?? 20;
                    
                    // Check if this is an existing day or a new one
                    if (!empty($dayData['id'])) {
                        // UPDATE EXISTING DAY
                        $day = Day::where('id', $dayData['id'])
                            ->where('event_specialty_id', $eventSpecialty->id)
                            ->first();
                        
                        if ($day) {
                            $day->update([
                                'day_date' => $dayData['day_date'],
                                'number_per_hour' => $numberPerHour,
                            ]);
                            
                            Log::info("Updated Day ID: {$day->id}");
                        } else {
                            Log::warning("Day ID {$dayData['id']} not found, creating new one");
                            $day = Day::create([
                                'event_specialty_id' => $eventSpecialty->id,
                                'day_date' => $dayData['day_date'],
                                'number_per_hour' => $numberPerHour,
                            ]);
                            Log::info("Created new Day ID: {$day->id}");
                        }
                    } else {
                        // CREATE NEW DAY
                        $day = Day::create([
                            'event_specialty_id' => $eventSpecialty->id,
                            'day_date' => $dayData['day_date'],
                            'number_per_hour' => $numberPerHour,
                        ]);
                        Log::info("Created new Day ID: {$day->id}");
                    }
                    
                    $processedDayIds[] = $day->id;
                    
                    // ============================================
                    // ✅ HOUR PROCESSING WITH WORKING DELETION
                    // ============================================
                    
                    /**
                     * Helper function to normalize time to HH:MM:SS format
                     */
                    $normalizeTime = function($time) {
                        // Handle Carbon objects, DateTime, or strings
                        if (is_object($time) && method_exists($time, 'format')) {
                            return $time->format('H:i:s');
                        }
                        // Handle string times
                        $timestamp = strtotime($time);
                        if ($timestamp === false) {
                            return $time; // Return as-is if can't parse
                        }
                        return date('H:i:s', $timestamp);
                    };
                    
                    // Build array of times that should exist (normalized)
                    $desiredHourTimes = array_map(function($h) use ($normalizeTime) {
                        return $normalizeTime($h['time']);
                    }, $dayData['hours']);
                    
                    // Get all existing hours for this day
                    $existingHours = Hour::where('day_id', $day->id)->get();
                    
                    // Create a map of normalized time => hour for quick lookup
                    $existingHoursByTime = [];
                    foreach ($existingHours as $hour) {
                        $normalizedTime = $normalizeTime($hour->time);
                        $existingHoursByTime[$normalizedTime] = $hour;
                    }
                    
                    // Track IDs of hours we want to KEEP
                    $hourIdsToKeep = [];
                    
                    // Process each hour from the payload
                    foreach ($dayData['hours'] as $hourData) {
                        $time = $normalizeTime($hourData['time']);
                        
                        if (isset($existingHoursByTime[$time])) {
                            // Hour exists, just update max_allowed
                            $existingHoursByTime[$time]->update([
                                'max_allowed' => $numberPerHour
                            ]);
                            Log::info("Updated Hour ID: {$existingHoursByTime[$time]->id}, Time: {$time}");
                            
                            // ✅ KEEP THIS HOUR
                            $hourIdsToKeep[] = $existingHoursByTime[$time]->id;
                        } else {
                            // Hour doesn't exist, create it
                            $newHour = Hour::create([
                                'day_id' => $day->id,
                                'time' => $time,
                                'max_allowed' => $numberPerHour,
                                'counter' => 0,
                            ]);
                            Log::info("Created new Hour ID: {$newHour->id}, Time: {$time}");
                            
                            // ✅ KEEP THIS HOUR
                            $hourIdsToKeep[] = $newHour->id;
                        }
                    }
                    
                    // Never silently delete bookings: refuse removing a slot that has appointments
                    $this->refuseIfBooked(
                        Appointment::whereIn('hour_id', Hour::where('day_id', $day->id)->whereNotIn('id', $hourIdsToKeep)->pluck('id')),
                        'لا يمكن حذف ساعة بها مواعيد محجوزة (يوم ' . $day->day_date . ')'
                    );

                    // ✅ DELETE hours that are NOT in the keep list
                    if (count($hourIdsToKeep) > 0) {
                        $deletedCount = Hour::where('day_id', $day->id)
                            ->whereNotIn('id', $hourIdsToKeep)
                            ->delete();
                    } else {
                        // If no hours to keep, delete all (safety check)
                        $deletedCount = Hour::where('day_id', $day->id)->delete();
                    }
                    
                    if ($deletedCount > 0) {
                        Log::info("Deleted {$deletedCount} obsolete hours from Day ID: {$day->id}");
                    }
                    
                    // ============================================
                    // END of Hour Processing
                    // ============================================
                }
                
                $this->refuseIfBooked(
                    Appointment::whereIn('day_id', Day::where('event_specialty_id', $eventSpecialty->id)->whereNotIn('id', $processedDayIds)->pluck('id')),
                    'لا يمكن حذف يوم به مواعيد محجوزة'
                );

                // Delete days not in the payload
                $deletedDays = Day::where('event_specialty_id', $eventSpecialty->id)
                    ->whereNotIn('id', $processedDayIds)
                    ->delete();
                
                if ($deletedDays > 0) {
                    Log::info("Deleted {$deletedDays} days from EventSpecialty ID: {$eventSpecialty->id}");
                }
            }
            
            $this->refuseIfBooked(
                Appointment::whereIn('event_specialty_id', EventSpecialty::where('event_id', $event->id)->whereNotIn('id', $processedEventSpecialtyIds)->pluck('id')),
                'لا يمكن حذف تخصص به مواعيد محجوزة'
            );

            // Delete event_specialties not in the payload
            $deletedEventSpecialties = EventSpecialty::where('event_id', $event->id)
                ->whereNotIn('id', $processedEventSpecialtyIds)
                ->delete();
            
            if ($deletedEventSpecialties > 0) {
                Log::info("Deleted {$deletedEventSpecialties} event specialties from Event ID: {$event->id}");
            }
        }

        DB::commit();

        // Reload relationships
        $event->load([
            'eventSpecialties.specialty',
            'eventSpecialties.doctors',
            'eventSpecialties.days.hours',
        ]);

        return response()->json([
            'message' => 'تم تحديث الحدث بنجاح',
            'event' => $event
        ], 200);

    } catch (RuleException $e) {
        DB::rollBack();
        return $e->render();
    } catch (\Exception $e) {
        DB::rollBack();
        
        Log::error('Event update failed', [
            'event_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request_keys' => array_keys($request->all())
        ]);

        return response()->json([
            'message' => 'حدث خطأ أثناء تحديث الحدث',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
        ], 500);
    }
}

/**
 * Helper method to create a new EventSpecialty
 */
private function createEventSpecialty($eventId, $specData, $isSaturated)
{
    return EventSpecialty::create([
        'event_id' => $eventId,
        'specialty_id' => $specData['id'],
        'description' => $specData['description'] ?? '',
        'is_saturated' => $isSaturated,
        'has_dilatation' => (bool) ($specData['has_dilatation'] ?? false),
        'max_number' => 100,
        'start_time' => '08:00:00',
        'addition_capacity' => 0,
        'flag' => 'Open',
        'is_active' => true,
    ]);
}
    /**
     * Toggle event active status
     */
    public function toggleActive($id)
    {
        try {
            $event = Event::findOrFail($id);
            $event->is_active = !$event->is_active;
            $event->save();

            return response()->json([
                'message' => $event->is_active 
                    ? 'تم تفعيل الحدث بنجاح' 
                    : 'تم إلغاء تفعيل الحدث بنجاح',
                'event' => $event
            ], 200);
        } catch (\Exception $e) {
            Log::error('Toggle active failed', ['event_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'حدث خطأ أثناء تغيير حالة الحدث',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }
public function showWithRules($id)
{
    try {
        $event = Event::findOrFail($id);
        $rules = $event->rules; // Get rules using the relationship
        
        return response()->json([
            'event' => $event,
            'rules' => $rules
        ], 200);
    } catch (\Exception $e) {
        Log::error('Error fetching event with rules:', ['id' => $id, 'error' => $e->getMessage()]);
        return response()->json([
            'message' => 'فشل في تحميل بيانات الحدث',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
        ], 500);
    }
}
    /**
     * Archive an event
     */
public function archive($id)
{
    $event = Event::find($id);
    
    if (!$event) {
        return response()->json([
            'success' => false,
            'message' => 'الحدث غير موجود'
        ], 404);
    }

    if ($event->is_archived) {
        return response()->json([
            'success' => false,
            'message' => 'الحدث مؤرشف بالفعل'
        ], 400);
    }

    try {
        DB::transaction(function () use ($event) {
            $appointments = Appointment::where('event_id', $event->id)->get();

            if ($appointments->count() > 0) {
                foreach ($appointments as $appointment) {
                    // Get raw attributes to avoid casting issues
                    $archiveData = $appointment->getAttributes();
                    
                    // Format date fields properly
                    $archiveData['birthday'] = $appointment->birthday 
                        ? Carbon::parse($appointment->birthday)->format('Y-m-d') 
                        : null;
                    
                    $archiveData['created_at'] = $appointment->created_at 
                        ? Carbon::parse($appointment->created_at)->format('Y-m-d H:i:s') 
                        : null;
                    
                    $archiveData['updated_at'] = $appointment->updated_at 
                        ? Carbon::parse($appointment->updated_at)->format('Y-m-d H:i:s') 
                        : null;
                    
                    // Add archive metadata
                    $archiveData['archived_at'] = now()->format('Y-m-d H:i:s');
                    $archiveData['archived_reason'] = 'Event archived manually';
                    
                    // Insert into archive
                    DB::table('appointments_archive')->insert($archiveData);
                }

                // Delete original appointments
                Appointment::where('event_id', $event->id)->delete();
                
                Log::info('Appointments archived', [
                    'event_id' => $event->id,
                    'count' => $appointments->count()
                ]);
            }

            // Mark event as archived
            $event->update([
                'is_archived' => true,
                'is_active' => false
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'تم أرشفة الحدث بنجاح',
            'event' => $event->fresh(),
            'archived_count' => $event->archivedAppointments()->count()
        ], 200);

    } catch (\Exception $e) {
        Log::error('Event archival failed', [
            'event_id' => $id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء أرشفة الحدث',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}


    /**
     * Restore an archived event
     */
    public function restore($id)
    {
        $event = Event::find($id);
        
        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'الحدث غير موجود'
            ], 404);
        }

        if (!$event->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'الحدث غير مؤرشف'
            ], 400);
        }

        try {
            DB::transaction(function () use ($event) {
                $archivedAppointments = AppointmentArchive::where('event_id', $event->id)->get();

                if ($archivedAppointments->count() > 0) {
                    foreach ($archivedAppointments as $archived) {
                        $appointmentData = $archived->toArray();
                        unset($appointmentData['archived_at']);
                        unset($appointmentData['archived_reason']);
                        
                        Appointment::create($appointmentData);
                    }

                    AppointmentArchive::where('event_id', $event->id)->delete();
                }

                $event->is_archived = false;
                $event->is_active = true;
                $event->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'تم استعادة الحدث بنجاح',
                'event' => $event->fresh()
            ], 200);

        } catch (\Exception $e) {
            Log::error('Event restoration failed', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء استعادة الحدث',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }

    /**
     * Delete an event
     */
    public function destroy($id)
    {
        $event = Event::find($id);
        
        if (!$event) {
            return response()->json(['message' => 'الحدث غير موجود'], 404);
        }

        try {
            DB::beginTransaction();
            $event->delete();
            DB::commit();

            return response()->json(['message' => 'تم حذف الحدث بنجاح'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Event deletion failed', [
                'event_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء حذف الحدث',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }

/**
 * Assign event to all users (bulk assignment for training)
 * POST /api/events/{eventId}/assign-to-all-users
 */
public function assignToAllUsers(Request $request, $eventId)
{
    try {
        $event = Event::findOrFail($eventId);
        
        // Get all users except SuperAdmin (role_id = 1)
        $users = User::where('role_id', '!=', 1)->get();
        
        if ($users->isEmpty()) {
            return response()->json([
                'message' => 'لا يوجد مستخدمين لتعيينهم'
            ], 404);
        }

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($users as $user) {
            // Only update if user has a valid role (2-7)
            if (in_array($user->role_id, [2, 3, 4, 5, 6, 7])) {
                $user->event_id = $eventId;
                $user->save();
                $updatedCount++;
            } else {
                $skippedCount++;
            }
        }

        Log::info('Bulk event assignment completed', [
            'event_id' => $eventId,
            'event_name' => $event->name,
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount
        ]);

        return response()->json([
            'message' => "تم تعيين الحدث '{$event->name}' لـ {$updatedCount} مستخدم بنجاح",
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            'event' => [
                'id' => $event->id,
                'name' => $event->name
            ]
        ], 200);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'message' => 'الحدث غير موجود'
        ], 404);
    } catch (\Exception $e) {
        Log::error('Bulk event assignment failed', [
            'event_id' => $eventId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'message' => 'حدث خطأ أثناء تعيين الحدث للمستخدمين',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
        ], 500);
    }
}}