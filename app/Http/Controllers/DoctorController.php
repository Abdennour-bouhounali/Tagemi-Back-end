<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Doctor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;

class DoctorController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum')
        ];
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user_role = Auth::user()->role_id;
        if($user_role == 1){
            return ['doctors'=> Doctor::all()];
        }else{
            return ['message' => 'You are not autorized'];
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user_role = Auth::user()->role_id;

        if($user_role == 1){
            
          // Validate the request data
          $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'specialty_id' => 'required|exists:specialties,id',
            'start_time' => 'required|date_format:H:i:s',
            'end_time' => 'required|date_format:H:i:s|after:start_time',
        ]);
        

        // Store the appointment data
        $doctor = Doctor::create([
            'name' => $validatedData['name'],
            'specialty_id' => $validatedData['specialty_id'],
            'start_time' => $validatedData['start_time'],
            'end_time'=> $validatedData['end_time']
        ]);

        // Return a response
        return response()->json(['message' => 'Doctor created successfully', 'doctor' => $doctor], 201);

        }else{
            return ['message' => 'You are not autorized'];
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Doctor $doctor)
    {
        $user_role = Auth::user()->role_id;

        if($user_role == 1){
            
            return $doctor;

        }else{
            return ['message' => 'You are not autorized'];
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Doctor $doctor)
    {
        $user_role = Auth::user()->role_id;

        if ($user_role == 1) {
            // Validate the request data
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'specialty_id' => 'required|exists:specialties,id',
                'start_time' => 'required|date_format:H:i:s',
                'end_time' => 'required|date_format:H:i:s|after:start_time',
            ]);

            // Update the specialty data
            $doctor->update($validatedData);

            // Return a response
            return response()->json(['message' => 'Doctor updated successfully', 'doctor' => $doctor], 200);
        } else {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Doctor $doctor)
    {
        $doctor->delete();
        return response()->json(['message' => 'Doctor Deleted successfully'], 201);
    }
}
