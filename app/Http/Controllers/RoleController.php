<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

use App\Models\Role;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;

class RoleController extends Controller implements HasMiddleware
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
            $admins = User::whereIn('role_id', [1, 3, 4, 5])
            ->with(['role', 'specialty'])
            ->get();
            $SpecialtiesAdmins = User::where('role_id',4)->get();
            $specialities = Specialty::all();
            return ['users'=> User::all() , 'admins' => $admins,'specialities'=>$specialities,'roles'=>Role::all(),'SpecialtiesAdmins'=>$SpecialtiesAdmins];
        }else{
            return ['message' => 'You are not autorized'];
        }
    }



    public function ChangeRole(Request $request){
        $user_role = Auth::user()->role_id;

         // Check if the authenticated user has the required role to change another user's role
    if ($user_role == 1) {
        
        // Validate the request data
        $validatedData = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|integer'
        ]);

        // Find the user by the provided user_id
        $user = User::find($validatedData['user_id']);

        // Update the user's role
        $user->role_id = $validatedData['role'];
        $user->save();
        $admins = User::whereIn('role_id', [1, 3, 4, 5])
            ->with(['role', 'specialty'])
            ->get();
        // Return a success response
        return response()->json(['message' => 'Role changed successfully', 'user' => $user,'admins'=>$admins], 200);
    } else {
        // Return an unauthorized response
        return response()->json(['message' => 'You are not authorized'], 403);
    }
    }



    public function AssignSpeciality(Request $request)
    {
        // Validate the incoming JSON request structure
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'speciality_id' => 'required|exists:specialties,id',
        ]);
    
        $user_role = Auth::user()->role_id;
    
        // Check if the authenticated user has the required role to assign specialties
        if ($user_role == 1) {
            // Begin a database transaction
            DB::beginTransaction();
    
            try {
                // Iterate through each item in the JSON array
               
                    $user = User::find($request->user_id);

                    if($user->role_id == 4){                 
                        if ($user) {
                            // Update the speciality_id attribute of the user
                            $user->specialty_id= $request->speciality_id;
                            $user->save();
                        }
                    }else{
                        return response()->json(['message' => 'The Admin Should be a Speciality Admin']);
                    }
                
    
                // Commit the transaction
                DB::commit();
    
                // Return a success response
                return response()->json(['message' => 'Specialities assigned successfully'], 200);
            } catch (\Exception $e) {
                // Rollback the transaction in case of any errors
                DB::rollback();
    
                // Return an error response
                return response()->json(['message' => 'Failed to assign specialities', 'error' => $e->getMessage()], 500);
            }
        } else {
            // Return an unauthorized response
            return response()->json(['message' => 'You are not authorized'], 403);
        }
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
    public function show(Role $role)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Role $role)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role)
    {
        //
    }
}
