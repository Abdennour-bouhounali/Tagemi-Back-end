<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict a route to a set of roles.
 *
 * Usage: ->middleware('role:super_admin,recipient_admin')
 * Role names map to User::ROLES; numeric ids are accepted too.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $allowed = array_map(function ($role) {
            return is_numeric($role) ? (int) $role : (User::ROLES[$role] ?? null);
        }, $roles);

        if (!in_array((int) $user->role_id, $allowed, true)) {
            return response()->json(['message' => 'You are not authorized to perform this action.'], 403);
        }

        return $next($request);
    }
}
