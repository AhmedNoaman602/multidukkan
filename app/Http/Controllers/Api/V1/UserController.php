<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
{
    $user = auth()->user();

    $visibleRoles = match($user->role) {
        'tenant_admin'  => ['tenant_admin', 'store_manager', 'store_staff'],
        'store_manager' => ['store_staff'],
        default         => [],
    };

   $users = User::where('tenant_id', $user->tenant_id)
    ->where('id', '!=', $user->id)
    ->whereIn('role', $visibleRoles)
    ->when($user->role === 'store_manager', fn($q) => $q->where('store_id', $user->store_id))
    ->get()
    ->map(fn($u) => [
        'id'       => $u->id,
        'name'     => $u->name,
        'email'    => $u->email,
        'role'     => $u->role,
        'store_id' => $u->store_id,
    ]);

    return response()->json(['data' => $users]);
}

    public function store(Request $request)
    {
        $authUser = auth()->user();

        $allowedRoles = match($authUser->role) {
            'tenant_admin'  => ['store_manager', 'store_staff'],
            'store_manager' => ['store_staff'],
            default         => [],
        };

        if (empty($allowedRoles)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role'     => 'required|in:' . implode(',', $allowedRoles),
            'store_id' => 'required|exists:stores,id',
        ]);

        $newUser = User::create([
            'tenant_id' => $authUser->tenant_id,
            'store_id'  => $request->store_id,
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'role'      => $request->role,
        ]);

        return response()->json([
            'data' => [
                'id'       => $newUser->id,
                'name'     => $newUser->name,
                'email'    => $newUser->email,
                'role'     => $newUser->role,
                'store_id' => $newUser->store_id,
            ]
        ], 201);
    }

    public function destroy(User $user)
    {
        $authUser = auth()->user();

        if ($user->tenant_id !== $authUser->tenant_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($user->id === $authUser->id) {
            return response()->json(['message' => 'Cannot delete yourself.'], 422);
        }

        $allowedRoles = match($authUser->role) {
            'tenant_admin'  => ['store_manager', 'store_staff'],
            'store_manager' => ['store_staff'],
            default         => [],
        };

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json(['message' => 'Unauthorized to delete this user.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }
}