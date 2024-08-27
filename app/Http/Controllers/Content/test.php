<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Activity;

class ActivityController extends Controller
{
    public function index()
    {
        return Activity::with('type')->get();
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
        return response()->json($activity, 201);
    }

    public function show($id)
    {
        return Activity::with('type')->findOrFail($id);
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

    public function destroy($id)
    {
        $activity = Activity::findOrFail($id);
        $activity->delete();
        return response()->json(null, 204);
    }
}
