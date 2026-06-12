<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\Unit;
use App\Models\Tenant;
use App\Models\Customer;
class AuthController extends Controller
{
    public function register(Request $request){

$request->validate([
        'business_name' => 'required|string|max:255',
        'name'          => 'required|string|max:255',
        'email'         => 'required|email|unique:users,email',
        'password'      => 'required|string|min:8|confirmed',
    ]);


     $result = DB::transaction(function () use ($request) {
        $defaultUnits = ['حبة', 'متر', 'كيلو', 'علبة', 'لفة', 'طن', 'لتر', 'كرتونة', 'رول'];
        
        $tenant = Tenant::create([
            'name' => $request->business_name,
        ]);


foreach ($defaultUnits as $unit) {
    Unit::create([
        'tenant_id' => $tenant->id,
        'name'      => $unit,
    ]);
}
        $user = User::create([
            'tenant_id' => $tenant->id,
            'store_id'  => null,
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => bcrypt($request->password),
            'role'      => 'tenant_admin',
        ]);

        Customer::create([
    'tenant_id' => $tenant->id,
    'name'      => 'زبون نقدي',
    'phone'     => '00000000000',
    'is_walk_in' => true,  // hidden flag
]);

        return $user;
    });

    $token = $result->createToken('auth_token')->plainTextToken;

    return response()->json([
        'token' => $token,
        'user'  => [
            'id'        => $result->id,
            'name'      => $result->name,
            'email'     => $result->email,
            'role'      => $result->role,
            'tenant_id' => $result->tenant_id,
            'store_id'  => $result->store_id,
            'business_name' => $result->tenant->name,
            'has_store' => false,  // just registered, no stores yet      
        ]
    ], 201);
    }

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
            'business_name' => $user->tenant->name,
            'walk_in_customer_id' => Customer::where('tenant_id', $user->tenant_id)
    ->where('is_walk_in', true)
    ->value('id'),
            'has_store' => $user->tenant->stores()->exists(),
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

   public function me(Request $request)
{
    $user = $request->user();
    return response()->json([
        'id'            => $user->id,
        'name'          => $user->name,
        'email'         => $user->email,
        'role'          => $user->role,
        'tenant_id'     => $user->tenant_id,
        'store_id'      => $user->store_id,
        'business_name' => $user->tenant->name,
        'walk_in_customer_id' => Customer::where('tenant_id', $user->tenant_id)
    ->where('is_walk_in', true)
    ->value('id'),
        'has_store' => $user->tenant->stores()->exists(),
    ]);
}
}
