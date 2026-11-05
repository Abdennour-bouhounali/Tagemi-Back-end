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
     // First and family name, both in Arabic; `name` is their joined form
     $validatedData = $request->validate(User::nameRules() + [
        'email' => 'required|string|email|max:255|unique:users,email',
        'password' => 'required|string|min:8',
        'phone' => 'nullable|string|max:20',
    ], User::NAME_MESSAGES);

    // Store the user data
    $user = (new User)->setArabicName($validatedData['first_name'], $validatedData['family_name']);
    $user->fill([
        'email' => $validatedData['email'],
        'password' => $validatedData['password'], // hashed by the model cast
        'phone' => $validatedData['phone'] ?? null,
    ]);
    $user->save();
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

        if ($user->doctor && !$user->doctor->is_active) {
            return response()->json(['message' => 'هذا الحساب موقوف، تواصل مع الإدارة'], 403);
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


    /**
     * The signed-in account sets its own Arabic first and family name (asked
     * by the app while needs_name_update is true). A doctor's registry entry follows.
     */
    public function updateName(Request $request)
    {
        $data = $request->validate(User::nameRules(), User::NAME_MESSAGES);

        $user = $request->user();
        $user->setArabicName($data['first_name'], $data['family_name'])->save();
        $user->doctor?->update(['first_name' => $user->first_name, 'family_name' => $user->family_name]);

        return ['message' => 'حُفظ الاسم', 'user' => $user->only(['id', 'name', 'first_name', 'family_name', 'needs_name_update'])];
    }

    public function logout(Request $request){

        // Revoke only the token used for this request, so other devices stay logged in
        $request->user()->currentAccessToken()->delete();
        return ['messgae'=>'You are logout'];
    }
}
