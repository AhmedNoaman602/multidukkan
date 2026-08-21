<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Unit;
use App\Http\Requests\StoreUnitRequest;
class UnitController extends Controller
{
    public function index(){
        $units = Unit::where('tenant_id', auth()->user()->tenant_id) 
                    ->orderBy('name')
                    ->get();

                    return response()->json([
                        'data' => $units
                    ]);
    }

    public function store(StoreUnitRequest $request){
        $validated = $request->validated();

        $user = auth()->user();

        $exists = Unit::where('tenant_id', $user->tenant_id)
            ->where('name', $request->name)
            ->exists();

        if ($exists) {
            return response()->json(['message' => __('messages.unit_already_exists')], 422);
        }

        $unit = Unit::create([
            'tenant_id' => $user->tenant_id,
            'name'      => $request->name,
        ]);

        return response()->json(['data' => $unit], 201);
    }

    public function destroy(Unit $unit){
        if ($unit->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['message' => __('messages.unauthorized')], 403);
        }
        $unit->delete();
        return response()->json(['message' => __('messages.unit_deleted')]);
    }
}
