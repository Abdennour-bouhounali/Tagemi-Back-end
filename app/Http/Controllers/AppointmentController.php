<?php

namespace App\Http\Controllers;

use DateTime;
use DateInterval;
use Carbon\Carbon;
use App\Models\Specialty;
use App\Models\Appointment;
use App\Models\WaitingList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;

use App\Exports\AppointmentsExport;
use Maatwebsite\Excel\Facades\Excel;


class AppointmentController extends Controller implements HasMiddleware
{
    public function getTime(){
                    // Define the timezone for your city
        $timezone = 'Africa/Algiers'; // Replace with appropriate timezone if different

        // Create a Carbon instance for the given timezone
        $currentTime = Carbon::now(new \DateTimeZone($timezone));

        $formattedTime = $currentTime->format('H:i:s');
        return $formattedTime;
    }



    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum',except:['index','show','store','getPresentPatientsBySpecialty'])
        ];
    }



 
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $time = $this->getTime();
        
        $appointments = Appointment::with('specialty')
        ->get()
        ->groupBy('patient_id');
        return response()->json(['appoitments' => $appointments], 201);

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'birthday' => 'required|date',
            'residence' => 'required|string|max:255',
            'diseases' => 'nullable|string', // Optional field, can be null
            'phone' => 'required|string|max:20',
            'sex' => 'required|boolean', // Assuming sex is either 0 or 1
            'specialties' => 'required|array|min:1',
            'specialties.*.specialty_id' => 'required|exists:specialties,id',
        ]);
        
        // Get the authenticated user's ID
        $userId = 1;
         // Find the latest patient_id and increment it
        $lastAppointment = Appointment::orderBy('patient_id', 'desc')->first();
        
        $nextPatientId = $lastAppointment ? $lastAppointment->patient_id + 1 : 1;


    
        // Starting time for appointments
        $startingTime = '08:00:00';
        $Maxtime = '23:59:59';
        // Create appointments for each specialty
        $appointments = [];
        $minTime = new DateTime($Maxtime);
        $specialtyStatusDict = [];
        $waitinglist_message = '';
        $message = '';

        foreach ($validatedData['specialties'] as $specialty) {
            $specialtyId = $specialty['specialty_id'];
            $Speciality_chosed = Specialty::find($specialtyId);

            $specialtyStatusDict[$specialtyId] = $Speciality_chosed['Flag'];
            

            if($Speciality_chosed['Flag'] == 'Open'){



            }elseif($Speciality_chosed['Flag'] == 'WaitingList'){

                if($waitinglist_message !== '' ){

                    $waitinglist_message = $waitinglist_message . ' and ' . $Speciality_chosed['name'];
                }else{
                    $waitinglist_message = $Speciality_chosed['name'];
                }

            }

        }


        

        $open = false;
        foreach ($validatedData['specialties'] as $specialty) {
            $specialtyId = $specialty['specialty_id'];

                if($Speciality_chosed['Flag'] == 'Open'){

                
                // Retrieve the duration for the specialty in minutes
                $specialtyDuration = Specialty::find($specialtyId)->duration; // Assuming the duration is stored in minutes

                // Calculate the total number of minutes to add to the starting time
                $appointmentCount = Appointment::where('specialty_id', $specialtyId)->count();
                $totalMinutesToAdd = $appointmentCount * $specialtyDuration;
        
                // Calculate the new time
                $time = new DateTime($startingTime);
                $time->add(new DateInterval('PT' . $totalMinutesToAdd . 'M'));
                $specialty = Specialty::find($specialtyId);
                if ($specialty) {
                    $specialty->specialty_time = $time->format('H:i:s'); // Assuming `specialty_time` is a column in your specialties table
                    $specialty->save();
                }
                
                if ($time < $minTime) {
                    $minTime = $time;
                }
                $open = true;
            }
        }

        if($open){
            $message = 'نرجوا المجيء على الساعة ' . $minTime->format('H:i');
        }

    
        // Format the minimum time
        $formattedMinTime = $minTime->format('H:i:s');

        // Create appointments with the minimum time
        foreach ($validatedData['specialties'] as $specialty) {
            $specialtyId = $specialty['specialty_id'];
            $Speciality_chosed = Specialty::find($specialtyId);

            // Get the current highest position for the given specialty
            $maxPosition = Appointment::where('specialty_id', $specialtyId)->max('position');

            if($maxPosition < $Speciality_chosed['Max_Number']){
            // Assign the next position
            $position = $maxPosition ? $maxPosition + 1 : 1;
    
            $appointments[] = Appointment::create([
                'user_id' => $userId,
                'name' => $validatedData['name'],
                'lastName' => $validatedData['lastName'],
                'birthday' => $validatedData['birthday'],
                'residence' => $validatedData['residence'],
                'diseases' => $validatedData['diseases'],
                'phone' => $validatedData['phone'],
                'sex' => $validatedData['sex'],
                'patient_id' => $nextPatientId,
                'specialty_id' => $specialtyId,
                'specialty_order' => 1,
                'time' => $formattedMinTime,
                'position' => $position
            ]);
            

            }else{
                // Ensure $specialty is not null
                if ($Speciality_chosed) {
                    // Count the number of appointments with status 'Waiting List'
                    $waitingListNumber = Appointment::where('status', 'Waiting List')
                                                    ->where('specialty_id', $specialtyId)
                                                    ->count();

                    // Get the capacity for the specialty
                    $specialtyAdditionCapacity = $Speciality_chosed->Addition_Capacitif;

                    // Check if the waiting list exceeds the specialty's capacity
                    if ($waitingListNumber >= $specialtyAdditionCapacity) {
                        // Mark the specialty as closed
                        $Speciality_chosed->Flag = 'Closed';

                    } else {
                        // Mark the specialty as in the waiting list
                        $Speciality_chosed->Flag = 'WaitingList';

                        // Determine the next position in the list
                        $position = $maxPosition ? $maxPosition + 1 : 1;

                        // Create a new appointment
                        $appointments[] = Appointment::create([
                            'user_id' => $userId,
                            'name' => $validatedData['name'],
                            'lastName' => $validatedData['lastName'],
                            'birthday' => $validatedData['birthday'],
                            'residence' => $validatedData['residence'],
                            'diseases' => $validatedData['diseases'],
                            'phone' => $validatedData['phone'],
                            'sex' => $validatedData['sex'],
                            'patient_id' => $nextPatientId,
                            'specialty_id' => $specialtyId,
                            'specialty_order' => 1,
                            'time' => $formattedMinTime,
                            'position' => $position,
                            'status' => 'Waiting List'
                        ]);
                    }

                    // Save the updated specialty
                    $Speciality_chosed->save();
                }


                

            }
        }
    
        // Return a response
        return response()->json(['message' => $message, 'appointments' => $appointments, 'minTime' => $formattedMinTime,'waitinglist_message'=>$waitinglist_message], 201);
    }
    
    public function ConfirmPresenceDelay($id){
        $user_role = Auth::user()->role_id;
    
        if ($user_role == 5 || $user_role == 1) {
            // Get all appointments for the patient
            $appointments = Appointment::where('patient_id', $id)->get();
    
            // Check if there are any appointments
            if ($appointments->isEmpty()) {
                return response()->json(['message' => 'No appointments found for this patient'], 404);
            }
    
            // Update the status of all appointments to 'Present'
            $i = 0;
            foreach ($appointments as $appointment) {
                if($appointment->status != 'Pending'){
                    return ['message' => 'The Patient is Already Here'];
                }
                $specialtyId = $appointment->specialty_id;
    
                // Get the current highest position for the given specialty
                $maxPosition = Appointment::where('specialty_id', $specialtyId)->max('position');
        
                // Assign the next position
                $position = $maxPosition ? $maxPosition + 1 : 1;
                $appointment->position = $position;
    
                if($i == 0){
    
                    $appointment->status = 'Present';
                    
                }else{
                    $appointment->status = 'Waiting';
    
                }
                    $appointment->save();
                $i = $i + 1;
    
            }
    
            return response()->json(['message' => 'The Confirmation of Presence is done'], 201);
        } else {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    }
    
    public function SpecialCase($id){
        $user_role = Auth::user()->role_id;
    
        if ($user_role == 3 || $user_role == 1) {
            // Get all appointments for the patient
            $appointments = Appointment::where('patient_id', $id)->get();
    
            // Check if there are any appointments
            if ($appointments->isEmpty()) {
                return response()->json(['message' => 'No appointments found for this patient'], 404);
            }
    
            // Update the status of all appointments to 'Present'
            $i = 0;
            foreach ($appointments as $appointment) {

                if($i == 0){
    
                    $appointment->status = 'Present';
                    
                }else{
                    $appointment->status = 'Waiting';
    
                }

            $appointment->position = 0;
            $appointment->name = $appointment->name . ' ( Special Case )';
            $appointment->save();
            $i = $i + 1;
    
            }
    
            return response()->json(['message' => 'WE add the Special Case to Position 1','appointment'=>$appointment], 201);
        } else {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    }
    public function ConfirmPresence($id){
    $user_role =Auth::user()->role_id;

    if ($user_role == 5 || $user_role == 1) {
        // Get all appointments for the patient
        $appointments = Appointment::where('patient_id', $id)->get();

        // Check if there are any appointments
        if ($appointments->isEmpty()) {
            return response()->json(['message' => 'No appointments found for this patient'], 404);
        }

        // Update the status of all appointments to 'Present'
        $i = 0;
        foreach ($appointments as $appointment) {
            if($appointment->status != 'Pending'){
                return ['message' => 'The Patient is Already Here'];
            }

            if($i == 0){

                $appointment->status = 'Present';
                
            }else{
                $appointment->status = 'Waiting';

            }
                $appointment->save();
            $i = $i + 1;

        }

        return response()->json(['message' => 'The Confirmation of Presence is done'], 201);
    } else {
        return response()->json(['message' => 'You are not authorized'], 403);
    }
}



    /**
     * Display the specified resource.
     */
    public function show(Appointment $appointment)
    {
     return $appointment;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Appointment $appointment)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Appointment $appointment)
    {
        $appointment->delete();
    return response()->json(['message' => 'Appointment Deleted successfully'], 201);
    }
}
