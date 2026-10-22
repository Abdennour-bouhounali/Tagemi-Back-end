<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request){
     // Validate the request data. role_id is intentionally not accepted:
     // self-registered accounts are always SimpleUser, roles are granted by a super admin.
     $validatedData = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users,email',
        'password' => 'required|string|min:8',
        'phone' => 'nullable|string|max:20',
    ]);

    // Store the user data
    $user = User::create([
        'name' => $validatedData['name'],
        'email' => $validatedData['email'],
        'password' => $validatedData['password'], // hashed by the model cast
        'phone' => $validatedData['phone'] ?? null,
    ]);
    $user->forceFill(['role_id' => User::ROLE_SIMPLE_USER])->save();

     // Generate an API token
     $token = $user->createToken('auth')->plainTextToken;

     // Return a response
     return response()->json([
         'message' => 'User created successfully',
         'user' => $user,
         'token' => $token,
     ], 201);
    }

    public function login(Request $request){
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        $user = User::where('email',$request->email)->first();

        // Same response whether the email exists or not, to avoid account enumeration
        if(!$user || !Hash::check($request->password, $user->password)){
            return response()->json([
                'message' => 'Information Not Valide',
                'errors' => [
                    'email'=>['Information Not Valide']
                ]
            ], 422);
        }


        // Generate an API token
     $token = $user->createToken('auth')->plainTextToken;

     // Return a response
     return response()->json([
         'message' => 'User LogedIn successfully',
         'user' => $user,
         'token' => $token,
     ], 201);

    }


    public function logout(Request $request){

        // Revoke only the token used for this request, so other devices stay logged in
        $request->user()->currentAccessToken()->delete();
        return ['messgae'=>'You are logout'];
    }
}
