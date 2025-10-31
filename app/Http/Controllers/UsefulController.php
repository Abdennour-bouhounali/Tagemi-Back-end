<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Appointment;
use Illuminate\Http\Request;
use App\Exports\AppointmentsExport;
use App\Models\Parameter;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\User;

class UsefulController extends Controller
{
    public function getCurrentTime()
    {
        // Define the timezone for your city
        $timezone = 'Africa/Algiers'; // Replace with appropriate timezone if different

        // Create a Carbon instance for the given timezone
        $currentTime = Carbon::now(new \DateTimeZone($timezone));

        $formattedTime = $currentTime->format('H:i:s');

        // Return the current time as a JSON response
        return response()->json([
            'timezone' => $timezone,
            'current_time' => $formattedTime
        ]);
    }

    public function getStatistics()
    {
        $statistics = Appointment::select('specialty_id')
            ->selectRaw('SUM(status = "Pending") as pending_count')
            ->selectRaw('SUM(status = "Delay") as delay_count')
            ->selectRaw('SUM(status = "Waiting List") as waiting_list')
            ->selectRaw('COUNT(DISTINCT CASE WHEN status = "Present" OR status = "Waiting" THEN patient_id END) as present_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN status = "Completed" THEN patient_id END) as completed_count')
            ->selectRaw('COUNT(DISTINCT patient_id) as total_patients')
            ->with('specialty')
            ->groupBy('specialty_id')
            ->get();

            $residenceCounts = Appointment::select('residence')
        ->selectRaw('COUNT(*) as count')
        ->groupBy('residence')
        ->get();
        // Calculate the total counts
        $totalPending = $statistics->sum('pending_count');
        $totalWaiting_list = $statistics->sum('waiting_list');
        $totalDelay = $statistics->sum('delay_count');
        $totalPresent = $statistics->sum('present_count');
        $totalCompleted = $statistics->sum('completed_count');
        $totalPatients = Appointment::distinct('patient_id')->count('patient_id');

        // Calculate the number of males, females, and children under 10
        $now = now(); // Current date and time
        $malesCount = Appointment::where('sex', 1)->distinct('patient_id')->count('patient_id');
        $femalesCount = Appointment::where('sex', 0)->distinct('patient_id')->count('patient_id');
        $childrenUnder10Count = Appointment::whereRaw("TIMESTAMPDIFF(YEAR, birthday, ?) < 10", [$now])
            ->distinct('patient_id')
            ->count('patient_id');

        return response()->json([
            'statistics' => $statistics,
            'totals' => [
                'total_pending' => $totalPending,
                'total_delay' => $totalDelay,
                'total_present' => $totalPresent,
                'total_completed' => $totalCompleted,
                'total_patients' => $totalPatients,
                'total_males' => $malesCount,
                'total_females' => $femalesCount,
                'totalWaiting_list' => $totalWaiting_list,
                'total_children_under_10' => $childrenUnder10Count
            ],
            'residence_counts' => $residenceCounts
        ]);
    }

    public function exportAppointments(Request $request)
    {

        return Excel::download(new AppointmentsExport, 'GreenhouseData.csv');
        
    }

    public function startVisitDay(Request $request){
        $parameters = Parameter::find(1);
        $parameters->is_day_visits = !$parameters->is_day_visits;
        $parameters->save();
        return response()->json($parameters->is_day_visits);
    }

    public function DisplayHideAuth(Request $request){
        $parameters = Parameter::find(1);
        $parameters->is_aut_displayed = !$parameters->is_aut_displayed;
        $parameters->save();
        return response()->json($parameters->is_aut_displayed);
    }

    public function getstartDay(){
        return Parameter::find(1)->is_day_visits;
    }


    
}
