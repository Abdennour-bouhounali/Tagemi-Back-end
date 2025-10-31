<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\AppointmentArchive;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ArchivedEventStatisticsController extends Controller
{
    /**
     * Get specialty statistics
     */
    public function specialtyStats($eventId)
    {
        $event = Event::findOrFail($eventId);
        
        if (!$event->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'This event is not archived'
            ], 400);
        }

        $stats = AppointmentArchive::where('appointments_archive.event_id', $eventId) // FIX: Specify table name
            ->join('event_specialties', 'appointments_archive.event_specialty_id', '=', 'event_specialties.id')
            ->join('specialties', 'event_specialties.specialty_id', '=', 'specialties.id')
            ->select(
                'specialties.id',
                'specialties.name',
                DB::raw('COUNT(*) as total_appointments'),
                DB::raw('SUM(CASE WHEN appointments_archive.status = "Completed" THEN 1 ELSE 0 END) as completed'),
                DB::raw('SUM(CASE WHEN appointments_archive.status = "Cancelled" THEN 1 ELSE 0 END) as cancelled'),
                DB::raw('SUM(CASE WHEN appointments_archive.status = "NoShow" THEN 1 ELSE 0 END) as no_show'),
                DB::raw('SUM(CASE WHEN appointments_archive.status = "Pending" THEN 1 ELSE 0 END) as pending')
            )
            ->groupBy('specialties.id', 'specialties.name')
            ->get();

        return response()->json([
            'success' => true,
            'event' => $event,
            'statistics' => $stats
        ]);
    }

  /**
 * Get status statistics
 */
public function statusStats($eventId)
{
    $event = Event::findOrFail($eventId);
    
    if (!$event->is_archived) {
        return response()->json([
            'success' => false,
            'message' => 'This event is not archived'
        ], 400);
    }

    $totalCount = AppointmentArchive::where('event_id', $eventId)->count();

    // Fix: Use subquery or calculate percentage separately
    $stats = AppointmentArchive::where('event_id', $eventId)
        ->select(
            'status',
            DB::raw('COUNT(*) as count')
        )
        ->groupBy('status')
        ->get()
        ->map(function($stat) use ($totalCount) {
            // Calculate percentage after fetching data
            $stat->percentage = $totalCount > 0 
                ? round(($stat->count / $totalCount) * 100, 2) 
                : 0;
            return $stat;
        });

    return response()->json([
        'success' => true,
        'event' => $event,
        'total_appointments' => $totalCount,
        'statistics' => $stats
    ]);
}

    /**
     * Get demographic statistics (age & gender)
     */
    public function demographicStats($eventId)
    {
        $event = Event::findOrFail($eventId);
        
        if (!$event->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'This event is not archived'
            ], 400);
        }

        // Gender statistics
        $genderStats = AppointmentArchive::where('event_id', $eventId)
            ->select(
                'sex',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('sex')
            ->get();

        // Age distribution
        $ageStats = AppointmentArchive::where('event_id', $eventId)
            ->select(
                DB::raw('CASE 
                    WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) < 18 THEN "0-17"
                    WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 18 AND 30 THEN "18-30"
                    WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 31 AND 45 THEN "31-45"
                    WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 46 AND 60 THEN "46-60"
                    ELSE "60+"
                END as age_group'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('age_group')
            ->orderByRaw('FIELD(age_group, "0-17", "18-30", "31-45", "46-60", "60+")')
            ->get();

        return response()->json([
            'success' => true,
            'event' => $event,
            'gender_statistics' => $genderStats,
            'age_statistics' => $ageStats
        ]);
    }

    /**
     * Get geographic statistics
     */
    public function geographicStats($eventId)
    {
        $event = Event::findOrFail($eventId);
        
        if (!$event->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'This event is not archived'
            ], 400);
        }

        // By state
        $stateStats = AppointmentArchive::where('event_id', $eventId)
            ->select(
                'state',
                DB::raw('COUNT(*) as count')
            )
            ->whereNotNull('state')
            ->groupBy('state')
            ->orderBy('count', 'desc')
            ->get();

        // By city (top 20)
        $cityStats = AppointmentArchive::where('event_id', $eventId)
            ->select(
                'city',
                'state',
                DB::raw('COUNT(*) as count')
            )
            ->whereNotNull('city')
            ->groupBy('city', 'state')
            ->orderBy('count', 'desc')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'event' => $event,
            'state_statistics' => $stateStats,
            'city_statistics' => $cityStats
        ]);
    }

    /**
     * Get complete patients list
     */
    public function patientsList(Request $request, $eventId)
    {
        $event = Event::findOrFail($eventId);
        
        if (!$event->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'This event is not archived'
            ], 400);
        }

        $query = AppointmentArchive::where('appointments_archive.event_id', $eventId) // FIX: Specify table name
            ->with(['eventSpecialty.specialty', 'doctor', 'day', 'hour']);

        // Apply filters
        if ($request->has('status')) {
            $query->where('appointments_archive.status', $request->status); // FIX: Specify table name
        }

        if ($request->has('specialty_id')) {
            $query->whereHas('eventSpecialty', function($q) use ($request) {
                $q->where('specialty_id', $request->specialty_id);
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('appointments_archive.full_name', 'like', "%{$search}%") // FIX: Specify table name
                  ->orWhere('appointments_archive.phone', 'like', "%{$search}%") // FIX: Specify table name
                  ->orWhere('appointments_archive.patient_id', 'like', "%{$search}%"); // FIX: Specify table name
            });
        }

        $perPage = $request->get('per_page', 50);
        $appointments = $query->orderBy('appointments_archive.id')->paginate($perPage); // FIX: Specify table name

        return response()->json([
            'success' => true,
            'event' => $event,
            'appointments' => $appointments
        ]);
    }

    /**
     * Get comprehensive report
     */
    public function comprehensiveReport($eventId)
    {
        $event = Event::findOrFail($eventId);
        
        if (!$event->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'This event is not archived'
            ], 400);
        }

        $totalAppointments = AppointmentArchive::where('event_id', $eventId)->count();

        // Get all statistics in one response
        $report = [
            'event' => $event,
            'total_appointments' => $totalAppointments,
            
            // Status breakdown
            'status_breakdown' => AppointmentArchive::where('event_id', $eventId)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get(),
            
            // Specialty breakdown
            'specialty_breakdown' => AppointmentArchive::where('appointments_archive.event_id', $eventId) // FIX: Specify table name
                ->join('event_specialties', 'appointments_archive.event_specialty_id', '=', 'event_specialties.id')
                ->join('specialties', 'event_specialties.specialty_id', '=', 'specialties.id')
                ->select('specialties.name', DB::raw('COUNT(*) as count'))
                ->groupBy('specialties.id', 'specialties.name')
                ->get(),
            
            // Gender breakdown
            'gender_breakdown' => AppointmentArchive::where('event_id', $eventId)
                ->select('sex', DB::raw('COUNT(*) as count'))
                ->groupBy('sex')
                ->get(),
            
            // Age statistics
            'age_breakdown' => AppointmentArchive::where('event_id', $eventId)
                ->select(
                    DB::raw('CASE 
                        WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) < 18 THEN "0-17"
                        WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 18 AND 30 THEN "18-30"
                        WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 31 AND 45 THEN "31-45"
                        WHEN TIMESTAMPDIFF(YEAR, birthday, CURDATE()) BETWEEN 46 AND 60 THEN "46-60"
                        ELSE "60+"
                    END as age_group'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('age_group')
                ->get(),
            
            // Top 10 states
            'top_states' => AppointmentArchive::where('event_id', $eventId)
                ->select('state', DB::raw('COUNT(*) as count'))
                ->whereNotNull('state')
                ->groupBy('state')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'report' => $report
        ]);
    }
}