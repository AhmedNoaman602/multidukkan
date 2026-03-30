<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request){

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

       $user = User::where('email', $request->email)->first();

if (!$user || !Hash::check($request->password, $user->password)) {
    return response()->json(['message' => 'Invalid credentials.'], 401);
}

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
        'token' => $token,
        'user'  => [
            'id'        => $user->id,
            'name'      => $user->name,
            'email'     => $user->email,
            'role'      => $user->role,
            'store_id'  => $user->store_id,
            'tenant_id' => $user->tenant_id,
        ]
        ]);

    }
    
    public function logout(Request $request){
       $user = request()->user();
       $token = $user->currentAccessToken();

       if (method_exists($token, 'delete')) {
        $token->delete();
       } //condition is only for testing files

       return response()->json([
        'message' => 'Logged out successfully.'
       ]); 

    }

    public function me(Request $request){
        $user = request()->user();

        return response()->json([
            'user'  => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'role'      => $user->role,
                'store_id'  => $user->store_id,
                'tenant_id' => $user->tenant_id,
            ]
        ]);

    }
}
