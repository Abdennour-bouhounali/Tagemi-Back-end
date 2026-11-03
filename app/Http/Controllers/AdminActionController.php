<?php

namespace App\Http\Controllers;

use App\Models\AdminAction;
use App\Models\Event;
use Illuminate\Http\Request;

/**
 * Super admin: the audit trail of the staff space (see RecordAdminAction).
 * GET /api/admin-actions?user_id=&event_id=&date=&patient_id=&failed=1&page=
 */
class AdminActionController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'user_id' => 'nullable|integer',
            'event_id' => 'nullable|integer',
            'date' => 'nullable|date_format:Y-m-d',
            'patient_id' => 'nullable|integer',
            'action' => 'nullable|string|max:120',
            'failed' => 'nullable|boolean',
        ]);

        $page = AdminAction::query()
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['event_id'] ?? null, fn ($q, $v) => $q->where('event_id', $v))
            ->when($filters['date'] ?? null, fn ($q, $v) => $q->whereDate('created_at', $v))
            ->when($filters['patient_id'] ?? null, fn ($q, $v) => $q->where('subject->patient_id', (int) $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($request->boolean('failed'), fn ($q) => $q->where('status', '>=', 400))
            ->latest('id')
            ->paginate(50);

        $events = Event::whereIn('id', AdminAction::whereNotNull('event_id')->distinct()->pluck('event_id'))->pluck('name', 'id');

        return response()->json([
            'actions' => collect($page->items())->map(fn (AdminAction $a) => [
                'id' => $a->id,
                'label' => $a->label(),
                'action' => $a->action,
                'user_id' => $a->user_id,
                'user' => $a->user_name,
                'role_id' => $a->role_id,
                'event_id' => $a->event_id,
                'event' => $events[$a->event_id] ?? null,
                'subject' => $a->subject,
                'input' => $a->input,
                'status' => $a->status,
                'ip' => $a->ip,
                'at' => $a->created_at?->toIso8601String(),
            ]),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            // Filter choices: people and events that appear in the trail
            'users' => AdminAction::select('user_id', 'user_name', 'role_id')->whereNotNull('user_id')
                ->groupBy('user_id', 'user_name', 'role_id')->orderBy('user_name')->get(),
            'events' => $events->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'labels' => AdminAction::LABELS,
        ]);
    }
}
