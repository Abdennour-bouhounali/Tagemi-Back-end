<?php

namespace App\Http\Controllers;

use App\Models\Volunteer;
use Illuminate\Http\Request;

class VolunteerController extends Controller
{
    public function index()
    {
        return Volunteer::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'full_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date',
            'gender' => 'required|string|max:10',
            'phone_number' => 'required|string|max:20',
            'email_address' => 'required|email|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'relevant_skills' => 'nullable|string',
            'previous_volunteering_experience' => 'nullable|string',
            'professional_background' => 'nullable|string',
            'areas_of_interest' => 'nullable|string',
            'preferred_types_of_activities' => 'nullable|string',
            'reasons_for_volunteering' => 'nullable|string'
        ]);

        $volunteer = Volunteer::create($request->all());

        return response()->json($volunteer, 201);
    }

    // Method to delete a volunteer
    public function destroy($id)
    {
        // Find the volunteer by ID
        $volunteer = Volunteer::find($id);

        // Check if the volunteer exists
        if ($volunteer) {
            // Delete the volunteer
            $volunteer->delete();

            // Return a success response
            return response()->json(['message' => 'Volunteer deleted successfully.'], 200);
        } else {
            // Return an error response if not found
            return response()->json(['message' => 'Volunteer not found.'], 404);
        }
    }
}
