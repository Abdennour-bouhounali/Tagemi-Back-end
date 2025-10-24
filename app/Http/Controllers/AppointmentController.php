<?php

namespace App\Http\Controllers;

use DateTime;
use DateInterval;
use Carbon\Carbon;
use App\Models\Specialty;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\WaitingList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use App\Exports\AppointmentsExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;


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
    $event = Event::where('is_current', 1)->first();

    // ✅ If no active event
    if (!$event) {
        return response()->json([
            'message' => 'لا يوجد حدث نشط حالياً.'
        ], 404);
    }

    $event_id = $event->id;

    $appointments = Appointment::with('specialty')
        ->where('event_id', $event_id)
        ->get()
        ->groupBy('patient_id');

    return response()->json([
        'message' => 'تم جلب المواعيد بنجاح.',
        'appointments' => $appointments
    ], 200);
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
            'birthday' => 'required|date', // Ensures the input is in dd/mm/yyyy format
            'residence' => 'required|string|max:255',
            'diseases' => 'nullable|string', // Optional field, can be null
            'phone' => 'required|string|max:20',
            'sex' => 'required|boolean', // Assuming sex is either 0 or 1
            'specialties' => 'required|array|min:1',
            'specialties.*.specialty_id' => 'required|exists:specialties,id',
        ]);
        $event = Event::where('is_current', 1)->first();

        if($event){
        $event_id = $event->id;

        }else{
        $event_id = 0;

        }
    
        // Get the authenticated user's ID
        $userId = 1;
    
        // Handle diseases field default
        $validatedData['diseases'] = $validatedData['diseases'] ?? 'nothing';
    
        // Convert birthday format if needed
        $formattedBirthday = $validatedData['birthday'];
    
        // Check if an appointment with the same data already exists
        $existingAppointment = Appointment::where('name', $validatedData['name'])
                            ->where('lastName', $validatedData['lastName'])
                            ->where('birthday', $formattedBirthday)
                            ->where('residence', $validatedData['residence'])
                            ->where('phone', $validatedData['phone'])
                            ->where('sex', $validatedData['sex'])
                            ->first();
    
        if ($existingAppointment) {
            return response()->json(['message' => 'An appointment with the same data already exists.'], 409);
        }
    
        // Generate the next patient ID
        $lastAppointment = Appointment::orderBy('patient_id', 'desc')->first();
        $nextPatientId = $lastAppointment ? $lastAppointment->patient_id + 1 : 1;
    
        // Starting time for appointments
        $startingTime = '08:30:00';
        $maxTime = '23:59:59';
    
        $minTime = new DateTime($maxTime);
        $maxAppointmentTime = null; // Will be updated dynamically
        $message = '';
        $waitinglist_message = '';
    
        $speciality_order_id = 0;
        $open = false;
    
        // Loop through specialties
        foreach ($validatedData['specialties'] as $specialty) {
            $specialtyId = $specialty['specialty_id'];
            $Speciality_chosed = Specialty::find($specialtyId);
    
            if ($Speciality_chosed && $Speciality_chosed['Flag'] === 'Open') {
                $specialtyDuration = $Speciality_chosed->duration; // Duration in minutes
                $appointmentCount = Appointment::where('specialty_id', $specialtyId)
                                                ->where('specialty_order', 1)
                                                ->count();
    
                $totalMinutesToAdd = $appointmentCount * $specialtyDuration;
                if ($totalMinutesToAdd > 240) {
                    $totalMinutesToAdd += 80;
                }
    
                $time = new DateTime($startingTime);
                $time->add(new DateInterval('PT' . $totalMinutesToAdd . 'M'));
    
                if ($time < $minTime) {
                    $minTime = $time;
                    $speciality_order_id = $specialtyId;
    
                    // Calculate maxTime as one hour after minTime
                    $maxAppointmentTime = clone $minTime;
                    $maxAppointmentTime->add(new DateInterval('PT1H'));
                }
    
                $open = true;
            }
        }
    
        // Generate the message with minTime and maxTime
        if ($open && $maxAppointmentTime) {
            $message = "موعدكم ما بين الساعة " . $minTime->format('H:i') . " إلى الساعة " . $maxAppointmentTime->format('H:i');
        }

    
        // Format the minimum time
        $formattedMinTime = $minTime->format('H:i:s');
    
        // Create appointments
        $appointments = [];
        foreach ($validatedData['specialties'] as $specialty) {
            $specialtyId = $specialty['specialty_id'];
            $Speciality_chosed = Specialty::find($specialtyId);
    
            $maxPosition = Appointment::where('specialty_id', $specialtyId)->max('position');
    
            if ($maxPosition < $Speciality_chosed['Max_Number']) {
                $position = $maxPosition ? $maxPosition + 1 : 1;
    
                $appointments[] = Appointment::create([
                    'user_id' => $userId,
                    'name' => $validatedData['name'],
                    'lastName' => $validatedData['lastName'],
                    'birthday' => $formattedBirthday,
                    'residence' => $validatedData['residence'],
                    'diseases' => $validatedData['diseases'],
                    'phone' => $validatedData['phone'],
                    'sex' => $validatedData['sex'],
                    'patient_id' => $nextPatientId,
                    'specialty_id' => $specialtyId,
                    'event_id' => $event_id,
                    'specialty_order' => $speciality_order_id === $specialtyId ? 1 : 0,
                    'time' => $formattedMinTime,
                    'position' => $position
                ]);
            }
        }
    
        // Return response with message
        return response()->json([
            'message' => $message,
            'appointments' => $appointments,
            'minTime' => $formattedMinTime,
            'waitinglist_message' => $waitinglist_message,
        ], 201);
    }



     public function addMultiple(Request $request)
    {
         // Log the full request
        Log::info('Received AddMultipleAppointments request:', $request->all());

        // Optional: log only appointments array
        Log::info('Appointments data:', $request->input('appointments', []));

        // Optional: log the selected specialty
        Log::info('Selected specialty_id:', ['specialty_id' => $request->input('specialty_id')]);

        $validatedData = $request->validate([
            'event_id' => 'required|exists:events,id',
            'appointments' => 'required|array|min:1',
            'appointments.*.name' => 'required|string|max:255',
            'appointments.*.lastName' => 'required|string|max:255',
            'appointments.*.birthday' => 'required|date',
            'appointments.*.residence' => 'required|string|max:255',
            'appointments.*.diseases' => 'nullable|string',
            'appointments.*.phone' => 'required|string|max:20',
            'appointments.*.sex' => 'required|boolean',
            'specialty_id' => 'required|exists:specialties,id',
        ]);

        $event_id = $validatedData['event_id'];
        $specialty_id = $request->input('specialty_id');

        $specialty = Specialty::find($specialty_id);
        if (!$specialty || $specialty->Flag !== 'Open') {
            return response()->json(['message' => 'التخصص مغلق أو غير موجود.'], 400);
        }

        // Determine the starting time for appointments
        $startingTime = '08:30:00';
        $appointmentsCreated = [];

        // Get last patient ID
        $lastPatient = Appointment::orderBy('patient_id', 'desc')->first();
        $nextPatientId = $lastPatient ? $lastPatient->patient_id + 1 : 1;

        // Loop through each appointment in the request
        foreach ($validatedData['appointments'] as $appData) {

            // Calculate the position in specialty queue
            $maxPosition = Appointment::where('specialty_id', $specialty_id)->max('position');
            $position = $maxPosition ? $maxPosition + 1 : 1;

            // Calculate appointment time
            $appointmentTime = new DateTime($startingTime);
            $appointmentTime->add(new DateInterval('PT' . ($position - 1) * $specialty->duration . 'M'));

            // Create appointment
            $appointment = Appointment::create([
                'user_id' => 1,
                'event_id' => $event_id,
                'name' => $appData['name'],
                'lastName' => $appData['lastName'],
                'birthday' => $appData['birthday'],
                'residence' => $appData['residence'],
                'diseases' => $appData['diseases'] ?? 'nothing',
                'phone' => $appData['phone'],
                'sex' => $appData['sex'],
                'patient_id' => $nextPatientId,
                'specialty_id' => $specialty_id,
                'specialty_order' => 1,
                'time' => $appointmentTime->format('H:i:s'),
                'position' => $position,
            ]);

            $appointmentsCreated[] = $appointment;
            $nextPatientId++;
        }

        return response()->json([
            'message' => 'تم إضافة جميع المواعيد بنجاح.',
            'appointments' => $appointmentsCreated,
        ], 201);
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
                $maxPosition = Appointment::where('specialty_id', $specialtyId)->where('status','Present')->max('position');
        
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
            $appointment->name = $appointment->name . '(حالة خاصة)';
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
            $specialtyId = $appointment->specialty_id;
            // $specialtyId = $specialty['specialty_id'];
            // $Speciality_chosed = Specialty::find($specialtyId);

            // Get the current highest position for the given specialty
            $MaxorderList = Appointment::where('specialty_id', $specialtyId)->max('orderList');

            // Assign the next position
            $orderList =  $MaxorderList + 1 ;

            $appointment->orderList = $orderList;

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

    public function addComment(Request $request)
    {
        $validated = $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'comment' => 'required|string|max:255',
        ]);

        $appointment = Appointment::find($validated['appointment_id']);
        $appointment->comment = $validated['comment'];
        $appointment->save();

        return response()->json([
            'message' => 'Comment added successfully',
            'appointment' => $appointment,
        ],200);
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

    public function deleteDuplicates()
    {
        // Identify duplicate appointments with the same patient_id and specialty_id
        $duplicates = DB::table('appointments')
            ->select('id')
            ->whereIn('id', function ($query) {
                $query->selectRaw('MIN(id)')
                    ->from('appointments')
                    ->groupBy('patient_id', 'specialty_id') // Group by both patient_id and specialty_id
                    ->havingRaw('COUNT(*) > 1');
            })
            ->pluck('id');
    
        // Delete duplicate appointments
        DB::table('appointments')->whereIn('id', $duplicates)->delete();
    
        return response()->json(['message' => 'Duplicate appointments with the same patient_id and specialty_id deleted successfully.']);
    }
    
}
