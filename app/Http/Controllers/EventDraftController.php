<?php

namespace App\Http\Controllers;

use App\Models\EventDraft;
use Illuminate\Http\Request;

/**
 * Drafts of the new-event form, saved automatically while the super admin
 * types. Each super admin sees only their own drafts; EventController@store
 * deletes the draft the event was made from.
 */
class EventDraftController extends Controller
{
    private const RULES = [
        'name' => 'nullable|string|max:255',
        'data' => 'required|array',
    ];

    // The form's whole state: far below this, but a draft must not fill the database
    private const MAX_BYTES = 500000;

    public function index(Request $request)
    {
        $drafts = EventDraft::where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->get(['id', 'name', 'created_at', 'updated_at']);

        return response()->json(['drafts' => $drafts]);
    }

    public function show(Request $request, EventDraft $draft)
    {
        $this->authorizeOwner($request, $draft);

        return response()->json(['draft' => $draft]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $draft = EventDraft::create($data + ['user_id' => $request->user()->id]);

        return response()->json(['draft' => $draft->only(['id', 'name', 'updated_at'])], 201);
    }

    public function update(Request $request, EventDraft $draft)
    {
        $this->authorizeOwner($request, $draft);
        $draft->update($this->validated($request));

        return response()->json(['draft' => $draft->only(['id', 'name', 'updated_at'])]);
    }

    public function destroy(Request $request, EventDraft $draft)
    {
        $this->authorizeOwner($request, $draft);
        $draft->delete();

        return response()->json(['message' => 'حُذفت المسودة']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(self::RULES);
        if (strlen(json_encode($data['data'])) > self::MAX_BYTES) {
            abort(response()->json(['message' => 'المسودة كبيرة جدًا'], 422));
        }

        return $data;
    }

    private function authorizeOwner(Request $request, EventDraft $draft): void
    {
        abort_unless($draft->user_id === $request->user()->id, 404);
    }
}
