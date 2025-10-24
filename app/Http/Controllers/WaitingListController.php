<?php

namespace App\Http\Controllers;

use DateTime;
use DateInterval;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Specialty;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\Parameter;
use App\Models\WaitingList;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
class WaitingListController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum')
        ];
    }
    public function getTime(){
                // Define the timezone for your city
        $timezone = 'Africa/Algiers'; // Replace with appropriate timezone if different

        // Create a Carbon instance for the given timezone
        $currentTime = Carbon::now(new \DateTimeZone($timezone));

        $formattedTime = $currentTime->format('H:i:s');
        return $formattedTime;
        }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user_role = Auth::user()->role_id;
        $event = Event::where('is_current', 1)->first();
        if($event){
        $event_id = $event->id;

        }else{
        $event_id = 3;

        }
        if($user_role == 1 || $user_role == 4 || $user_role == 6){
            // Get all users with appointments where status is 'present'
            $presents = Appointment::where('status','Present')
                    ->where('event_id',$event_id)
                    ->with(['specialty:id,name']) // Select only id and name of specialty
                    ->select('name', 'specialty_id', 'specialty_order')
                    ->get();

            return response()->json(['presents' => $presents]);
        }else{
            return ['message' => 'You are not autorized'];
        }
    }


    public function getwaitinglist(){
      
        // Retrieve the first 10 'Present' status appointments for each specialty
        // get the event that has current_id = 1
        $event = Event::where('is_current', 1)->first();
        if($event){
        $event_id = $event->id;

        }else{
        $event_id = 0;

        }




        $appointments = Appointment::select('specialty_id', 'patient_id', 'name','lastName')
        ->where('event_id',$event_id)
        ->where('status', 'Present')
        ->orderBy('specialty_id')
        ->orderBy('orderList')
        ->get()
        ->groupBy('specialty_id')
        ->map(function ($appointments) {
            // Get the first 10 appointments for each specialty
            return $appointments->take(10)->values();
        });

        // Format the data into a structured array
        $data = $appointments->mapWithKeys(function ($appointments, $specialtyId) {
        return [
            $specialtyId => $appointments->map(function ($appointment) {
                return [
                    'patient_id' => $appointment->patient_id,
                    'name' => $appointment->name,
                    'lastName' => $appointment->lastName,
                ];
            }),
        ];
        });

        // Return the data as JSON
        return response()->json($data);
    }
    
    public function deleteAppointmentsAndAdmins()
    {
        // Delete all appointments
        Appointment::truncate();

        // Delete all admins except superadmin (role_id = 1)
        User::where('role_id', '<>', 1)->delete();
        $specialities = Specialty::all();
        $startTime = new DateTime('08:00:00');
        foreach ($specialities as $speciality){
            $speciality->specialty_time = $startTime;
            $speciality->save();
        }
        return response()->json(['message' => 'All appointments and admins (except superadmin) have been deleted successfully.']);
    }
  

    public function Completed($id){
        $user_role = Auth::user()->role_id;
        $Appointment = Appointment::find($id);
        if(!$Appointment){
            return response()->json(['message' => 'No appointments found'], 404);

        }
        $specility_id_Appointment = $Appointment->specialty_id;
        $patient_id_Appointment = $Appointment->patient_id;
        
        // return ['specility_id_waitingList'=>$specility_id_waitingList,'patient_id_waitingList'=>$patient_id_waitingList];
        $appointments = Appointment::where('patient_id',$patient_id_Appointment)->get();

        if($user_role == 4 || $user_role == 1){
            // Check if there are any appointments
            if ($appointments->isEmpty()) {
                return response()->json(['message' => 'No appointments found for this patient'], 404);
            }
           // Retrieve the appointment details
           $appointment = $appointments->firstWhere('specialty_id', $specility_id_Appointment);
           // return $appointment ; 

           if (!$appointment) {
               return response()->json(['message' => 'No appointments found'], 404);
           }

           // Update appointment status to 'Completed'
           $appointment->status = 'Completed';
           $appointment->save();
           
            // Retrieve the next appointment for the same patient
            $next_appointment = Appointment::where('patient_id', $appointment->patient_id)
            ->where('status', 'Waiting')
            ->first();
            
            

           
           
            if ($next_appointment) {
                $specialtyId = $next_appointment->specialty_id;


                $uniqueAppointments = DB::table('appointments')
                ->select('appointments.*')
                ->joinSub(
                    DB::table('appointments')
                        ->select('patient_id')
                        ->groupBy('patient_id')
                        ->havingRaw('COUNT(*) = 1'), // Patients with exactly one appointment
                    'unique_patients',
                    'appointments.patient_id',
                    '=',
                    'unique_patients.patient_id'
                )
                ->where('appointments.specialty_id', $specialtyId) // Filter by specific specialty
                ->get();

            



           
                if($uniqueAppointments){
                    $maxPosition = $uniqueAppointments->max('orderList'); 
                }else{
                    $maxPosition = Appointment::where('specialty_id', $specialtyId)->max('orderList');
                }
                    

                $next_appointment->status = 'Present';
                $next_appointment->orderList = $maxPosition+1;
                $next_appointment->save();
            }


           return response()->json(['message' => 'Patient added to showed in the next speciality'], 201);

        
        }else{
            return ['message' => 'You are not autorized'];

        }
    }

    public function Dilatation($id){
        $user_role = Auth::user()->role_id;
        $appointment = Appointment::find($id);
        if(!$appointment){
            return response()->json(['message' => 'No appointments found'], 404);

        }
    
        if($user_role == 4 || $user_role == 1){
                $maxPosition = Appointment::where('specialty_id', $appointment->specialty_id)->max('orderList');

                // Assign the next position
                $appointment->status = 'Dilatation';
                $appointment->save();

                return ['message' => 'The Patient get the last position'];

        }else{
            return ['message' => 'You are not autorized'];

        }
    }


    public function FinishedDilatation($id){
        $user_role = Auth::user()->role_id;
        $appointment = Appointment::find($id);

        if(!$appointment){
            return response()->json(['message' => 'No appointments found'], 404);
        }
    
        if($user_role == 4 || $user_role == 1){
                $maxPosition = Appointment::where('specialty_id', $appointment->specialty_id)->max('orderList');

                // Assign the next position
                $appointment->orderList = 0;
                $appointment->status = 'Present';
                $appointment->save();

                return ['message' => 'The Patient get the last position'];

        }else{
            return ['message' => 'You are not autorized'];

        }
    }




public function Commingsoon(Request $request, $id)
{
    $user_role = Auth::user()->role_id;
    $appointment = Appointment::find($id);

    if (!$appointment) {
        return response()->json(['message' => 'No appointments found'], 404);
    }

    if ($user_role == 4 || $user_role == 1) {
        $position = $request->input('position'); // ✅ Get it from the body

        if (!$position) {
            return response()->json(['message' => 'Position is required'], 400);
        }

        $appointment->orderList = $position;
        $appointment->status = 'Commingsoon';
        $appointment->save();

        return ['message' => 'The Patient was moved to position '.$position];
    } else {
        return ['message' => 'You are not authorized'];
    }
}


public function alterSpeciality($id)
{
    $user_role = Auth::user()->role_id;

    // Find the selected appointment
    $appointment = Appointment::find($id);
    if (!$appointment) {
        return response()->json(['message' => 'No appointment found'], 404);
    }

    // Only allowed roles
    if (!in_array($user_role, [1, 4])) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }

    // Get all appointments of the same patient
    $patientAppointments = Appointment::where('patient_id', $appointment->patient_id)
        ->where('id', '!=', $appointment->id) // exclude the current one
        ->get();

    if ($patientAppointments->isEmpty()) {
        return response()->json(['message' => 'No other appointments for this patient'], 404);
    }

    // For example, swap with the first other appointment (you can change logic)
    $otherAppointment = $patientAppointments->first();

    // Swap positions (orderList) between the selected appointment and the other one
    $tempOrder = $appointment->orderList;
    $appointment->orderList = $otherAppointment->orderList;
    $otherAppointment->orderList = $tempOrder;

    // Save both
    $appointment->save();
    $otherAppointment->save();

    return response()->json([
        'message' => 'Positions swapped successfully',
        'swapped_with' => $otherAppointment->id,
    ], 200);
}


    public function Absent($id){
        $user_role = Auth::user()->role_id;
        $appointment = Appointment::find($id);
        if(!$appointment){
            return response()->json(['message' => 'No appointments found'], 404);

        }
    
        
        // return ['specility_id_waitingList'=>$specility_id_waitingList,'patient_id_waitingList'=>$patient_id_waitingList];

        if($user_role == 4 || $user_role == 1){
            // Check if there are any appointments
                // if ($appointment->isEmpty()) {
                //     return response()->json(['message' => 'No appointments found for this patient'], 404);
                // }
                // Get the current highest position for the given specialty
                $maxPosition = Appointment::where('specialty_id', $appointment->specialty_id)->max('orderList');

                // Assign the next position
                $position = $maxPosition ? $maxPosition + 1 : 1;
                $appointment->orderList = $position;

                $appointment->save();
                return ['message' => 'The Patient get the last position'];

        }else{
            return ['message' => 'You are not autorized'];

        }
    }

    public function GetWaitingListBySpeciality(Request $request,$id){
        $user_role = Auth::user()->role_id;
        $event = Event::where('is_current', 1)->first();
        if($event){
        $event_id = $event->id;

        }else{
        $event_id = 0;

    
        }

        $user_speciality_id = Auth::user()->speciality;
        $speciality = Specialty::find($id)->name;
        // return ['info'=>Auth::user()];
        if ($user_role == 4||$user_role == 1 || $user_role == 6) {
            // Subquery to get the minimum time and minimum id for each patient


        // Join the subquery back to the appointments table to get full details
        $appointments = Appointment::where('specialty_id', $id)
            ->where('event_id', $event_id)
            ->whereIn('status', ['Present', 'Dilatation','Commingsoon'])
            ->orderBy('orderList', 'asc')
            ->get();

        return response()->json(['appointments' => $appointments,'speciality' => $speciality]);
        } else {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    }


    public function OnDelay(){

        $time = $this->getTime();

        $isDay = Parameter::find(1)->is_day_visits;

        
    }
    
    public function delete(){

    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(WaitingList $waitingList)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, WaitingList $waitingList)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(WaitingList $waitingList)
    {
        //
    }
}
