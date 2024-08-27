<?php

namespace App\Http\Controllers\Content;

use App\Models\Media;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    public function index()
    {
        return Activity::with(['media'])->get();
    }

    public function showByActivitiesType($id){
        $activities = Activity::where('type_id',$id)->with('media')->get();
        return $activities;
    }
    
    public function store(Request $request)
    {
        $request->validate([
            'title_en' => 'required|string|max:255',
            'title_ar' => 'required|string|max:255',
            'description_en' => 'required|string',
            'description_ar' => 'required|string',
            'type_id' => 'required|integer|exists:types,id',
            'date' => 'required|date',
        ]);

        $activity = Activity::create($request->all());
        return response()->json(['message'=>'Well done','activity'=>$activity], 201);
    }


    public function show($id)
    {
        return Activity::with('media')->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'title_en' => 'required|string|max:255',
            'title_ar' => 'required|string|max:255',
            'description_en' => 'required|string',
            'description_ar' => 'required|string',
            'type_id' => 'required|integer|exists:types,id',
            'date' => 'required|date',
        ]);

        $activity = Activity::findOrFail($id);
        $activity->update($request->all());
        return response()->json($activity, 200);
    }

    
    public function makeSpecial($id)
    {
        $activity = Activity::find($id);
    
        if (!$activity) {
            return response()->json(['message' => 'Activity not found'], 404);
        }
    
        $activity->featured = !$activity->featured;
        $activity->save();
    
        return response()->json(['message' => 'Alternated Successfully'], 200);
    }

    public function destroy(Activity $activity)
    {
      
    }
    
}
  try {
            // Start a transaction to ensure both operations succeed or fail together
            DB::beginTransaction();
            
            // Delete associated media
            Media::where('activity_id', $activity->id)->delete();
            
            // Delete the activity
            $activity->delete();
            
            // Commit the transaction
            DB::commit();
    
            // Return success response
            return response()->json(['message' => 'Activity deleted successfully'], 204);
        } catch (\Exception $e) {
            // Rollback the transaction in case of an error
            DB::rollBack();
    
            // Return error response
            return response()->json(['error' => 'Failed to delete activity'], 500);
        }