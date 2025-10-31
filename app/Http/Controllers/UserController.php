<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Get users by role ID
     */
    public function getByRole(Request $request)
    {
        $roleId = $request->query('role');

        if (!$roleId) {
            return response()->json([
                'message' => 'role parameter is required'
            ], 400);
        }

        try {
            $users = User::where('role_id', $roleId)
                ->select('id', 'name', 'email', 'role_id')
                ->get();

            return response()->json([
                'users' => $users
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get users by role failed', [
                'role_id' => $roleId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء جلب المستخدمين',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }
    

    /**
     * Get users by specific role ID
     */
    public function getByRoleId($roleId)
    {
        try {
            $users = User::where('role_id', $roleId)
                ->select('id', 'name', 'email', 'role_id')
                ->get();

            return response()->json([
                'users' => $users
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get users by role failed', [
                'role_id' => $roleId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء جلب المستخدمين',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }
public function getdisplayAuth(Request $request)
{
    try {
        // Check if user is authenticated
        if (!Auth::check()) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Get authenticated user
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        // Load relationships including event specialties
        $user->load(['role', 'event.eventSpecialties.specialty']);
        
        // Extract unique specialties from event
        $specialties = [];
        if ($user->event && $user->event->eventSpecialties) {
            $specialties = $user->event->eventSpecialties
                ->pluck('specialty')
                ->filter() // Remove nulls
                ->unique('id')
                ->values()
                ->toArray();
        }
        
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_id' => $user->role_id,
            'role' => $user->role ? $user->role->name : null,
            'event_id' => $user->event_id,
            'event' => $user->event,
            'specialties' => $specialties, // Add specialties here
        ], 200);

    } catch (\Exception $e) {
        Log::error('Error in getdisplayAuth', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'user_id' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'An error occurred while fetching user data',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}
}