<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Stream one medical document of an appointment. Access (role + event)
     * is enforced by the route middleware.
     */
    public function show($id, $index)
    {
        $appointment = Appointment::findOrFail($id);
        $documents = array_values($appointment->medical_documents ?? []);
        $path = $documents[(int) $index] ?? null;

        // Stored paths are always "medical-documents/<file>"; refuse anything else
        if (!$path || !preg_match('#^medical-documents/[A-Za-z0-9._-]+$#', $path)) {
            abort(404);
        }

        // Legacy uploads may still be on the public disk until
        // `php artisan medical-documents:make-private` has been run.
        foreach ([Appointment::DOCUMENTS_DISK, 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, basename($path), [
                    'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
        }

        abort(404);
    }
}
