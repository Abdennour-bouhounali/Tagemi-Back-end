<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Get all users with their roles and events
     */
/**
 * Get all users
 * 
 * @return \Illuminate\Http\JsonResponse
 */
public function index()
{
    try {
        // ✅ Only load relationships that exist on User model
        $users = User::with([
            'role',           // User belongs to Role
            'event'           // User belongs to Event
        ])
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json([
            'users' => $users
        ], 200);

    } catch (\Exception $e) {
        Log::error('Error fetching users:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'message' => 'فشل في تحميل المستخدمين',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
        ], 500);
    }
}
    /**
     * Get users by role ID (query parameter)
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
                ->with(['role', 'event'])
                ->select('id', 'name', 'email', 'phone', 'role_id', 'event_id')
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
     * Get users by specific role ID (path parameter)
     */
    public function getByRoleId($roleId)
    {
        try {
            $users = User::where('role_id', $roleId)
                ->with(['role', 'event'])
                ->select('id', 'name', 'email', 'phone', 'role_id', 'event_id')
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
     * Get authenticated user display info
     */
    public function getdisplayAuth(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'User not found'
                ], 404);
            }

            $user->load(['role', 'event.eventSpecialties.specialty']);
            
            $specialties = [];
            if ($user->event && $user->event->eventSpecialties) {
                $specialties = $user->event->eventSpecialties
                    ->pluck('specialty')
                    ->filter()
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
                'specialties' => $specialties,
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

    /**
     * Get single user details
     */
    public function show($userId)
    {
        try {
            $user = User::with(['role', 'event'])
                ->findOrFail($userId);

            return response()->json([
                'user' => $user
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'المستخدم غير موجود'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Get user failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء جلب بيانات المستخدم',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Update user information
     */
    public function update(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $userId,
            'phone' => 'sometimes|nullable|string|max:20',
            'password' => 'sometimes|nullable|string|min:6',
        ], [
            'name.max' => 'الاسم طويل جداً',
            'email.email' => 'البريد الإلكتروني غير صالح',
            'email.unique' => 'البريد الإلكتروني مستخدم بالفعل',
            'password.min' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'فشل التحقق من البيانات',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::findOrFail($userId);
            
            $updateData = [];
            
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            
            if ($request->has('email')) {
                $updateData['email'] = $request->email;
            }
            
            if ($request->has('phone')) {
                $updateData['phone'] = $request->phone;
            }
            
            if ($request->filled('password')) {
                $updateData['password'] = Hash::make($request->password);
            }
            
            $user->update($updateData);

            return response()->json([
                'message' => 'تم تحديث بيانات المستخدم بنجاح',
                'user' => $user->load(['role', 'event'])
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'المستخدم غير موجود'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Update user failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء تحديث بيانات المستخدم',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Delete user
     */
    public function destroy($userId)
    {
        try {
            $user = User::findOrFail($userId);
            
            // Prevent deleting SuperAdmin
            if ($user->role_id === 1) {
                return response()->json([
                    'message' => 'لا يمكن حذف المدير العام'
                ], 403);
            }
            
            // Prevent self-deletion
            if (Auth::id() == $userId) {
                return response()->json([
                    'message' => 'لا يمكنك حذف حسابك الخاص'
                ], 403);
            }

            // Clear event admin references if this user is an admin
            Event::where('special_admin_id', $userId)->update(['special_admin_id' => null]);
            Event::where('recipient_admin_id', $userId)->update(['recipient_admin_id' => null]);
            Event::where('check_admin_id', $userId)->update(['check_admin_id' => null]);
            
            $user->delete();

            return response()->json([
                'message' => 'تم حذف المستخدم بنجاح'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'المستخدم غير موجود'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Delete user failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء حذف المستخدم',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Assign user to event and role
     */
    public function assignEvent(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'nullable|exists:events,id',
            'role_id' => 'required|exists:roles,id',
        ], [
            'event_id.exists' => 'الحدث غير موجود',
            'role_id.required' => 'يجب اختيار المهمة',
            'role_id.exists' => 'المهمة غير موجودة',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'فشل التحقق من البيانات',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::findOrFail($userId);
            
            // Prevent changing SuperAdmin role
            if ($user->role_id === 1 && $request->role_id != 1) {
                return response()->json([
                    'message' => 'لا يمكن تغيير مهمة المدير العام'
                ], 403);
            }
            
            $user->update([
                'event_id' => $request->event_id,
                'role_id' => $request->role_id
            ]);

            // Update event admin columns if applicable
            if ($request->event_id) {
                $event = Event::find($request->event_id);
                
                if ($event) {
                    if ($request->role_id == 3) { // Special Admin
                        $event->update(['special_admin_id' => $user->id]);
                    } elseif ($request->role_id == 5) { // Recipient Admin
                        $event->update(['recipient_admin_id' => $user->id]);
                    } elseif ($request->role_id == 4) { // Check Admin
                        $event->update(['check_admin_id' => $user->id]);
                    }
                }
            }

            return response()->json([
                'message' => 'تم تعيين المستخدم بنجاح',
                'user' => $user->load(['role', 'event'])
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'المستخدم غير موجود'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Assign user failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء تعيين المستخدم',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Remove user from event (unassign)
     */
    public function unassignEvent($userId)
    {
        try {
            $user = User::findOrFail($userId);
            
            // Clear event admin references
            if ($user->event_id) {
                $event = Event::find($user->event_id);
                if ($event) {
                    if ($event->special_admin_id == $userId) {
                        $event->update(['special_admin_id' => null]);
                    }
                    if ($event->recipient_admin_id == $userId) {
                        $event->update(['recipient_admin_id' => null]);
                    }
                    if ($event->check_admin_id == $userId) {
                        $event->update(['check_admin_id' => null]);
                    }
                }
            }
            
            $user->update([
                'event_id' => null,
                'role_id' => 2 // Reset to SimpleUser
            ]);

            return response()->json([
                'message' => 'تم إلغاء تعيين المستخدم بنجاح',
                'user' => $user->load(['role', 'event'])
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'المستخدم غير موجود'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Unassign user failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء إلغاء تعيين المستخدم',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }

    /**
     * Get users assigned to a specific event
     */
    public function getByEvent($eventId)
    {
        try {
            $users = User::where('event_id', $eventId)
                ->with(['role'])
                ->select('id', 'name', 'email', 'phone', 'role_id', 'event_id')
                ->get();

            return response()->json([
                'users' => $users
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get users by event failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء جلب المستخدمين',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي'
            ], 500);
        }
    }
}