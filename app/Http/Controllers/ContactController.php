<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index()
{
    $contacts = Contact::all();
    return response()->json($contacts);
}

    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'message' => 'required|string',
            'wilaya' => 'required|string|max:255',
            'type_message' => 'required|string|max:255',
        ]);

        $contact = Contact::create($request->all());

        return response()->json(['message' => 'شكرا للتواصل معنا ,سنتصل بكم لاحقا.', 'contact' => $contact], 201);
    }

    public function destroy($id)
    {
        // Find the volunteer by ID
        $contact = Contact::find($id);

        // Check if the volunteer exists
        if ($contact) {
            // Delete the volunteer
            $contact->delete();

            // Return a success response
            return response()->json(['message' => 'contact deleted successfully.'], 200);
        } else {
            // Return an error response if not found
            return response()->json(['message' => 'contact not found.'], 404);
        }
    }
}
