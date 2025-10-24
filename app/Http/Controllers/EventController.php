<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EventController extends Controller
{
    // List all events
    public function index()
    {
        // Order by current first (is_current DESC), then by date ascending
        $events = Event::orderBy('date', 'desc')
                    ->get();

        return response()->json(['events' => $events], 200);
    }
    public function currentEvent()
    {
        $event = Event::where('is_current', true)->first();
        if (!$event) {
            return response()->json(['message' => 'No current event found'], 404);
        }
        return response()->json(['event' => $event], 200);
    }


    // Store a new event
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'date' => 'required|date',
            'place' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_current' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $event = Event::create($request->all());
        return response()->json(['event' => $event], 201);
    }

    // Show a single event
    public function show($id)
    {
        $event = Event::find($id);
        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }
        return response()->json(['event' => $event], 200);
    }

    // Update an event
    public function update(Request $request, $id)
    {
        $event = Event::find($id);
        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'date' => 'sometimes|required|date',
            'place' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_current' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $event->update($request->all());
        return response()->json(['event' => $event], 200);
    }
public function archiveAppointments($id)
{
    $event = Event::findOrFail($id);
    $event->is_archived = true;
    $event->save();

    // Get all appointments of this event
    $appointments = $event->appointment;

    if ($appointments->isEmpty()) {
        return response()->json(['message' => 'لا توجد مواعيد للأرشفة'], 404);
    }

    // Archive each appointment
    foreach ($appointments as $appointment) {
        \App\Models\AppointmentArchive::create($appointment->toArray());
    }

    // Delete from main table
    $event->appointment()->delete();

    return response()->json([
        'message' => 'تم أرشفة جميع المواعيد بنجاح',
    ]);
}

    // Delete an event
    public function destroy($id)
    {
        $event = Event::find($id);
        if (!$event) {
            return response()->json(['message' => 'Event not found'], 404);
        }

        $event->delete();
        return response()->json(['message' => 'Event deleted'], 200);
    }

        public function toggle(Event $event)
    {
        $event->is_current = !$event->is_current;
        $event->save();

        return response()->json([
            'message' => 'Event status updated successfully.',
            'event' => $event
        ]);
    }

}
