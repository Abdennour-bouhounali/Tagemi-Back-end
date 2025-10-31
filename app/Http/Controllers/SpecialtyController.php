<?php

namespace App\Http\Controllers;

use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpecialtyController extends Controller
{
    /**
     * Get all specialties with their CheckAdmin
     */
    public function index()

    {
        $specialties = Specialty::all();

        return response()->json([
            'specialties' => $specialties
        ], 200);
    }

    /**
     * Store a new specialty WITHOUT auto-creating CheckAdmin
     * CheckAdmin will be created when event is created
     */
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:255|unique:specialties,name',
    ], [
        'name.required' => 'اسم التخصص مطلوب',
        'name.unique' => 'هذا التخصص موجود بالفعل',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'فشل التحقق من البيانات',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // THIS WAS MISSING - Create the specialty
        $specialty = Specialty::create([
            'name' => $request->name
        ]);

        return response()->json([
            'message' => 'تم إضافة التخصص بنجاح',
            'specialty' => $specialty
        ], 201);

    } catch (\Exception $e) {
        Log::error('Specialty creation failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => 'حدث خطأ أثناء إضافة التخصص',
            'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
        ], 500);
    }
}

    /**
     * Update specialty
     */
    public function update(Request $request, $id)
    {
        $specialty = Specialty::find($id);
        
        if (!$specialty) {
            return response()->json(['message' => 'التخصص غير موجود'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:specialties,name,' . $id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'فشل التحقق من البيانات',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $specialty->update(['name' => $request->name]);

            return response()->json([
                'message' => 'تم تحديث التخصص بنجاح',
                'specialty' => $specialty
            ], 200);

        } catch (\Exception $e) {
            Log::error('Specialty update failed', [
                'specialty_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء تحديث التخصص',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }

    /**
     * Delete specialty
     */
    public function destroy($id)
    {
        $specialty = Specialty::find($id);
        
        if (!$specialty) {
            return response()->json(['message' => 'التخصص غير موجود'], 404);
        }

        DB::beginTransaction();
        try {
            $specialty->delete();
            DB::commit();

            return response()->json([
                'message' => 'تم حذف التخصص بنجاح'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Specialty deletion failed', [
                'specialty_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء حذف التخصص',
                'error' => config('app.debug') ? $e->getMessage() : 'خطأ داخلي في الخادم'
            ], 500);
        }
    }

   
}