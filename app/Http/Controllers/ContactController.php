<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Get all contacts ordered by treated status (untreated first) and then by creation date
     */
    public function index()
    {
        $contacts = Contact::orderBy('treated', 'asc')
                          ->orderBy('created_at', 'desc')
                          ->get();
        return response()->json($contacts);
    }

    /**
     * Store a new contact message
     */
    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'message' => 'required|string',
            'state' => 'required|string|max:255',
            'commun' => 'required|string|max:255',
            'type_message' => 'required|string|max:255',
        ]);

        $contact = Contact::create($request->all());

        return response()->json([
            'message' => 'شكرا للتواصل معنا ,سنتصل بكم لاحقا.',
            'contact' => $contact
        ], 201);
    }

    /**
     * Toggle the treated status of a contact
     */
    public function toggleTreated($id)
    {
        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json(['message' => 'Contact not found.'], 404);
        }

        $contact->treated = !$contact->treated;
        $contact->treated_at = $contact->treated ? now() : null;
        $contact->save();

        return response()->json([
            'message' => $contact->treated ? 'تم تحديد الرسالة كمعالجة' : 'تم إلغاء تحديد الرسالة كمعالجة',
            'contact' => $contact
        ], 200);
    }

    /**
     * Mark a contact as treated
     */
    public function markAsTreated($id)
    {
        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json(['message' => 'Contact not found.'], 404);
        }

        $contact->treated = true;
        $contact->treated_at = now();
        $contact->save();

        return response()->json([
            'message' => 'تم تحديد الرسالة كمعالجة',
            'contact' => $contact
        ], 200);
    }

    /**
     * Mark a contact as untreated
     */
    public function markAsUntreated($id)
    {
        $contact = Contact::find($id);

        if (!$contact) {
            return response()->json(['message' => 'Contact not found.'], 404);
        }

        $contact->treated = false;
        $contact->treated_at = null;
        $contact->save();

        return response()->json([
            'message' => 'تم إلغاء تحديد الرسالة كمعالجة',
            'contact' => $contact
        ], 200);
    }

    /**
     * Delete a contact
     */
    public function destroy($id)
    {
        $contact = Contact::find($id);

        if ($contact) {
            $contact->delete();
            return response()->json(['message' => 'Contact deleted successfully.'], 200);
        } else {
            return response()->json(['message' => 'Contact not found.'], 404);
        }
    }

    /**
     * Get statistics for contacts
     */
    public function getStatistics()
    {
        $total = Contact::count();
        $treated = Contact::where('treated', true)->count();
        $untreated = Contact::where('treated', false)->count();
        
        $byType = Contact::selectRaw('type_message, count(*) as count')
                        ->groupBy('type_message')
                        ->get();

        return response()->json([
            'total' => $total,
            'treated' => $treated,
            'untreated' => $untreated,
            'by_type' => $byType
        ]);
    }
}