<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request){
     // Validate the request data
     $validatedData = $request->validate([
        'role_id' => 'integer|exists:roles,id',
        'name' => 'required|string|max:255',
        'email' => 'required|string|email|max:255|unique:users,email',
        'password' => 'required|string|min:8',
        'phone' => 'nullable|string|max:20',
    ]);

    // Store the user data
    $user = User::create([
        'role_id' => $request->input('role_id', 2), // Default to 1 if not provided
        'name' => $validatedData['name'],
        'email' => $validatedData['email'],
        'password' => Hash::make($validatedData['password']), // Hash the password
        'phone' => $validatedData['phone'],
    ]);

     // Generate an API token
     $token = $user->createToken($validatedData['name'])->plainTextToken;

     // Return a response
     return response()->json([
         'message' => 'User created successfully',
         'user' => $user,
         'token' => $token,
     ], 201);
    }

    public function login(Request $request){
        $request->validate([
            'email' => 'required|email|exists:users',
            'password' => 'required'
        ]);

        $user = User::where('email',$request->email)->first();

        if(!$user || !Hash::check($request->password, $user->password)){
            return [
                'errors' => [
                    'email'=>['Information Not Valide']
                ]
            ];
        }


        // Generate an API token
     $token = $user->createToken($user->name)->plainTextToken;

     // Return a response
     return response()->json([
         'message' => 'User LogedIn successfully',
         'user' => $user,
         'token' => $token,
     ], 201);

    }


    public function logout(Request $request){

        $request->user()->tokens()->delete();
        return ['messgae'=>'You are logout'];
    }
}
