<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;

class SpecialtyController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum',except:['index'])
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {

            
            $specialties = Specialty::with('users')->get();
            return response()->json(['specialties' => $specialties]);

        // return Specialty::all();
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
            'duration' => 'required|integer',
            'Total_Duration'=> ['required', 'date_format:H:i:s'],
            'Addition_Capacitif'=> 'required|integer'
        ], [
            'Total_Duration.date_format' => 'The Total Duration must be in the format HH:MM:SS.'
        ]);

        $Total_Duration = Carbon::createFromFormat('H:i:s', $validatedData['Total_Duration']);

        $Total_Duration_in_minutes = $Total_Duration->hour * 60 + $Total_Duration->minute + $Total_Duration->second / 60;

        // Perform the division

        $result = ceil($Total_Duration_in_minutes / $validatedData['duration']);


        // Store the appointment data
        $speciality = Specialty::create([
            'name' => $validatedData['name'],
            'duration' =>$validatedData['duration'],
            'Max_Number'=>$result,
            'Addition_Capacitif'=>$validatedData['Addition_Capacitif']
        ]);

        // Return a response
        return response()->json(['message' => 'Speciality created successfully', 'speciality' => $speciality,'Total_Duration'=>$Total_Duration], 201);

        }else{
            return ['message' => 'You are not autorized'];
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Specialty $specialty)
    {
        $user_role = Auth::user()->role_id;

        if($user_role == 1){
            
            return $specialty;

        }else{
            return ['message' => 'You are not autorized'];
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Specialty $specialty)
    {
        $user_role = Auth::user()->role_id;

        if ($user_role == 1) {
            // Validate the request data
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'duration' => 'required|integer',
                'Total_Duration'=> ['required', 'date_format:H:i:s']
            ]);

            // Update the specialty data
            $specialty->update($validatedData);

            // Return a response
            return response()->json(['message' => 'Specialty updated successfully', 'specialty' => $specialty], 200);
        } else {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Specialty $specialty)
    {
        $user_role = Auth::user()->role_id;
    
        if ($user_role == 1) {
            try {
            // Set specialty_id to 0 for users associated with this specialty
            User::where('specialty_id', $specialty->id)->update(['specialty_id' => 6]);

                $specialty->delete();
                return response()->json(['message' => 'Specialty deleted successfully'], 200);
            } catch (\Exception $e) {
                return response()->json(['message' => 'Error deleting specialty: ' . $e->getMessage()], 500);
            }
        } else {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    }
    



}
