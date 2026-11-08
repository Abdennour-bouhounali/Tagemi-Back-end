<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\VolunteerEvaluation;
use App\Models\VolunteerEvaluationAnswer;
use App\Models\VolunteerEvaluationParticipant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «تقييم المتطوعين»: the volunteers' debrief of an event (one per event).
 *
 * Volunteers scan the event's QR code, pick the role they worked in and
 * answer axis after axis: no account, a random token kept on the phone lets
 * them leave and resume. The super admin edits the questions, runs the
 * countdown and the phase shown on the projector screen, reads the results
 * and writes the general recommendations of each axis.
 */
class VolunteerEvaluationController extends Controller
{
    public const TOKEN_HEADER = 'X-Eval-Token';

    private const DEFAULT_FILE = 'data/volunteer-evaluation-default.json';
    private const ID_RULE = 'regex:/^[A-Za-z0-9_-]{1,40}$/';
    private const MAX_NOTES = 20;

    // ------------------------------------------------------------ public

    /** Questions and roles for the volunteer's phone. */
    public function publicShow(int $eventId): JsonResponse
    {
        return response()->json($this->findOrFail($eventId)->publicConfig());
    }

    /** First visit: the volunteer picks a role and gets their token. */
    public function join(Request $request, int $eventId): JsonResponse
    {
        $evaluation = $this->findOrFail($eventId);
        $this->ensureOpen($evaluation);
        $roleId = $this->validRole($request, $evaluation);

        $token = Str::random(48);
        $participant = $evaluation->participants()->create([
            'token_hash' => hash('sha256', $token),
            'role_id' => $roleId,
        ]);

        return response()->json([
            'token' => $token,
            'participant' => $this->mine($participant),
        ], 201);
    }

    /** The volunteer's own answers, to resume where they stopped. */
    public function me(Request $request, int $eventId): JsonResponse
    {
        $participant = $this->participant($request, $this->findOrFail($eventId));

        return response()->json(['participant' => $this->mine($participant)]);
    }

    public function updateRole(Request $request, int $eventId): JsonResponse
    {
        $evaluation = $this->findOrFail($eventId);
        $participant = $this->participant($request, $evaluation);
        $this->ensureOpen($evaluation);

        $participant->update(['role_id' => $this->validRole($request, $evaluation)]);

        return response()->json(['participant' => $this->mine($participant)]);
    }

    /** Saves one axis, «حفظ ومتابعة» (done) or «تخطّي» (skipped). */
    public function saveAxis(Request $request, int $eventId, string $axisId): JsonResponse
    {
        $evaluation = $this->findOrFail($eventId);
        $participant = $this->participant($request, $evaluation);
        $this->ensureOpen($evaluation);

        $axis = collect($evaluation->axes)->firstWhere('id', $axisId);
        if (!$axis) {
            return response()->json(['message' => 'هذا المحور لم يعد موجوداً. أعد فتح الصفحة.'], 404);
        }

        $data = $request->validate([
            'status' => 'required|in:done,skipped',
            'overall' => 'nullable|integer|between:0,5',
            'ratings' => 'nullable|array',
            'ratings.*' => 'nullable|integer|between:0,5',
            'positives' => 'nullable|array|max:' . self::MAX_NOTES,
            'negatives' => 'nullable|array|max:' . self::MAX_NOTES,
            'positives.*.text' => 'nullable|string|max:1000',
            'positives.*.reason' => 'nullable|string|max:1000',
            'positives.*.recommendation' => 'nullable|string|max:1000',
            'negatives.*.text' => 'nullable|string|max:1000',
            'negatives.*.reason' => 'nullable|string|max:1000',
            'negatives.*.recommendation' => 'nullable|string|max:1000',
        ]);

        // Only this axis' questions; unanswered ones are left out
        $questionIds = array_column($axis['qs'] ?? [], 'id');
        $ratings = array_filter(
            array_intersect_key($data['ratings'] ?? [], array_flip($questionIds)),
            fn ($value) => $value !== null
        );

        $participant->answers()->updateOrCreate(
            ['axis_id' => $axisId],
            [
                'volunteer_evaluation_id' => $evaluation->id,
                'status' => $data['status'],
                'overall' => $data['overall'] ?? null,
                'ratings' => array_map('intval', $ratings),
                'positives' => $this->cleanNotes($data['positives'] ?? []),
                'negatives' => $this->cleanNotes($data['negatives'] ?? []),
            ]
        );
        $participant->touch();

        return response()->json(['participant' => $this->mine($participant)]);
    }

    // ------------------------------------------------------- super admin

    /**
     * The evaluation of an event. The first time the super admin opens it,
     * it is created from the default file (title, roles, axes, questions of
     * the package), so the QR code works right away.
     */
    public function show(int $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $evaluation = $event->volunteerEvaluation ?? $this->createDefault($event);

        return response()->json([
            'event' => ['id' => $event->id, 'name' => $event->name, 'is_archived' => (bool) $event->is_archived],
            'evaluation' => $this->adminView($evaluation),
        ]);
    }

    /** Saves the roles, axes and questions edited by the super admin. */
    public function updateConfig(Request $request, int $eventId): JsonResponse
    {
        $evaluation = $this->findOrFail($eventId);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'roles' => 'required|array|min:1|max:50',
            'roles.*.id' => ['required', 'string', self::ID_RULE, 'distinct'],
            'roles.*.name' => 'required|string|max:190',
            'axes' => 'required|array|min:1|max:50',
            'axes.*.id' => ['required', 'string', self::ID_RULE, 'distinct'],
            'axes.*.title' => 'required|string|max:255',
            'axes.*.roles' => 'nullable|array',
            'axes.*.roles.*' => ['string', self::ID_RULE],
            'axes.*.qs' => 'required|array|min:1|max:80',
            'axes.*.qs.*.id' => ['required', 'string', self::ID_RULE],
            'axes.*.qs.*.g' => 'nullable|string|max:190',
            'axes.*.qs.*.t' => 'required|string|max:1000',
        ], [
            'axes.*.qs.required' => 'كل محور يحتاج سؤالاً واحداً على الأقل.',
            'axes.*.qs.min' => 'كل محور يحتاج سؤالاً واحداً على الأقل.',
            'roles.*.name.required' => 'اكتب اسم كل مهمة.',
            'axes.*.title.required' => 'اكتب عنوان كل محور.',
            'axes.*.qs.*.t.required' => 'اكتب نص كل سؤال.',
        ]);

        // Question ids must be unique across axes: ratings are keyed by them
        $questionIds = collect($data['axes'])->flatMap(fn ($a) => array_column($a['qs'], 'id'));
        if ($questionIds->count() !== $questionIds->unique()->count()) {
            throw ValidationException::withMessages(['axes' => 'يوجد سؤالان بنفس المعرّف.']);
        }

        $roleIds = array_column($data['roles'], 'id');
        $axes = array_map(fn ($a) => [
            'id' => $a['id'],
            'title' => trim($a['title']),
            'roles' => array_values(array_intersect($a['roles'] ?? [], $roleIds)),
            'qs' => array_map(fn ($q) => [
                'id' => $q['id'],
                'g' => trim((string) ($q['g'] ?? '')),
                't' => trim($q['t']),
            ], $a['qs']),
        ], $data['axes']);

        $evaluation->update([
            'title' => trim($data['title']),
            'roles' => array_map(fn ($r) => ['id' => $r['id'], 'name' => trim($r['name'])], $data['roles']),
            'axes' => $axes,
        ]);

        return response()->json(['evaluation' => $this->adminView($evaluation->fresh())]);
    }

    /** Countdown, phase shown on the screen, and whether answers are accepted. */
    public function updateSession(Request $request, int $eventId): JsonResponse
    {
        $evaluation = $this->findOrFail($eventId);

        $data = $request->validate([
            'is_open' => 'sometimes|boolean',
            'phase' => 'sometimes|in:eval,results',
            'duration_min' => 'sometimes|integer|between:1,240',
            // start | add5 | stop, computed on the server clock
            'timer' => 'sometimes|in:start,add5,stop',
        ]);

        $now = self::nowMs();
        $changes = array_intersect_key($data, array_flip(['is_open', 'phase', 'duration_min']));
        $duration = $data['duration_min'] ?? $evaluation->duration_min;

        match ($data['timer'] ?? null) {
            'start' => $changes['ends_at'] = $now + $duration * 60000,
            'add5' => $changes['ends_at'] = max($now, $evaluation->ends_at) + 5 * 60000,
            'stop' => $changes['ends_at'] = 0,
            default => null,
        };

        $evaluation->update($changes);

        return response()->json(['session' => $evaluation->session(), 'now' => $now]);
    }

    public function updateRecommendation(Request $request, int $eventId, string $axisId): JsonResponse
    {
        $evaluation = $this->findOrFail($eventId);
        $data = $request->validate(['text' => 'nullable|string|max:5000']);

        if (!collect($evaluation->axes)->firstWhere('id', $axisId)) {
            return response()->json(['message' => 'المحور غير موجود.'], 404);
        }

        $recommendations = $evaluation->recommendations ?? [];
        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            unset($recommendations[$axisId]);
        } else {
            $recommendations[$axisId] = $text;
        }
        $evaluation->update(['recommendations' => $recommendations]);

        return response()->json(['recommendations' => (object) $recommendations]);
    }

    /**
     * Live numbers for the dashboard and the projector screen: progress,
     * averages per axis and question, positives / negatives. No names: the
     * volunteers are anonymous.
     */
    public function results(int $eventId): JsonResponse
    {
        $evaluation = $this->findOrFail($eventId);
        $axes = collect($evaluation->axes ?? []);
        $axisIds = $axes->pluck('id')->all();
        $axisCount = max(1, count($axisIds));

        $participants = $evaluation->participants()->whereNotNull('role_id')->get(['id', 'role_id', 'created_at', 'updated_at']);
        $allAnswers = $evaluation->answers()
            ->whereIn('participant_id', $participants->pluck('id'))
            ->whereIn('axis_id', $axisIds ?: [''])
            ->get();
        $byAxis = $allAnswers->groupBy('axis_id');
        $byParticipant = $allAnswers->groupBy('participant_id');

        $people = $participants->map(function ($p) use ($byParticipant, $axisCount) {
            $mine = $byParticipant->get($p->id, collect());
            $done = $mine->where('status', 'done')->count();
            $skipped = $mine->where('status', 'skipped')->count();

            return [
                'id' => $p->id,
                'role_id' => $p->role_id,
                'done' => $done,
                'skipped' => $skipped,
                'progress' => round(($done + $skipped) / $axisCount, 4),
                'joined_at' => $p->created_at?->toIso8601String(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ];
        })->sortByDesc('progress')->values();

        $n = $people->count();
        $perAxis = $axes->map(function ($axis) use ($byAxis, $n) {
            $rows = $byAxis->get($axis['id'], collect());
            $done = $rows->where('status', 'done')->count();
            $skipped = $rows->where('status', 'skipped')->count();
            $overall = $rows->pluck('overall')->filter(fn ($v) => $v > 0);

            $questions = collect($axis['qs'] ?? [])->map(function ($q) use ($rows) {
                $values = $rows->map(fn ($r) => (int) (($r->ratings ?? [])[$q['id']] ?? 0))->filter(fn ($v) => $v > 0);

                return [
                    'id' => $q['id'],
                    'g' => $q['g'] ?? '',
                    't' => $q['t'],
                    'average' => $values->isEmpty() ? null : round($values->avg(), 2),
                    'count' => $values->count(),
                ];
            })->values();

            return [
                'id' => $axis['id'],
                'title' => $axis['title'],
                'done' => $done,
                'skipped' => $skipped,
                'progress' => $n ? round(($done + $skipped) / $n, 4) : 0,
                'average' => $overall->isEmpty() ? null : round($overall->avg(), 2),
                'overall_count' => $overall->count(),
                'questions' => $questions,
                'positives' => $rows->flatMap(fn ($r) => $r->positives ?? [])->values(),
                'negatives' => $rows->flatMap(fn ($r) => $r->negatives ?? [])->values(),
            ];
        })->values();

        $averages = $perAxis->pluck('average')->filter(fn ($v) => $v !== null);

        // Latest finished axes, for the screen's «آخر النشاطات» line
        $recent = $allAnswers->where('status', 'done')
            ->sortByDesc('updated_at')->take(5)
            ->map(fn ($a) => ['axis_id' => $a->axis_id, 'at' => $a->updated_at?->toIso8601String()])
            ->values();

        return response()->json([
            'now' => self::nowMs(),
            'session' => $evaluation->session(),
            'recommendations' => (object) ($evaluation->recommendations ?? []),
            'stats' => [
                'participants' => $n,
                'finished' => $people->where('progress', '>=', 1)->count(),
                'progress' => $n ? round($people->avg('progress'), 4) : 0,
                'average' => $averages->isEmpty() ? null : round($averages->avg(), 2),
            ],
            'axes' => $perAxis,
            'participants' => $people,
            'recent' => $recent,
        ]);
    }

    /** Removes one participant and their answers (a test entry, a duplicate). */
    public function destroyParticipant(int $eventId, int $participantId): JsonResponse
    {
        $evaluation = $this->findOrFail($eventId);
        $evaluation->participants()->whereKey($participantId)->firstOrFail()->delete();

        return response()->json(['message' => 'حُذفت المشاركة']);
    }

    /** Deletes the evaluation with all its answers (cascade); reopening starts again from the default file. */
    public function destroy(int $eventId): JsonResponse
    {
        $this->findOrFail($eventId)->delete();

        return response()->json(['message' => 'حُذف التقييم']);
    }

    // ----------------------------------------------------------- helpers

    private function createDefault(Event $event): VolunteerEvaluation
    {
        $config = json_decode(file_get_contents(resource_path(self::DEFAULT_FILE)), true);

        try {
            return $event->volunteerEvaluation()->create([
                'title' => $config['title'] ?? ('تقييم ' . $event->name),
                'roles' => $config['roles'],
                'axes' => $config['axes'],
                'recommendations' => [],
            ])->fresh();
        } catch (UniqueConstraintViolationException) {
            // Two tabs opened it at once: the other request created it
            return $event->volunteerEvaluation()->firstOrFail();
        }
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function findOrFail(int $eventId): VolunteerEvaluation
    {
        $evaluation = VolunteerEvaluation::with('event:id,name')->where('event_id', $eventId)->first();
        if (!$evaluation) {
            abort(response()->json(['message' => 'لا يوجد تقييم للمتطوعين في هذا الحدث.'], 404));
        }

        return $evaluation;
    }

    private function ensureOpen(VolunteerEvaluation $evaluation): void
    {
        if (!$evaluation->is_open) {
            abort(response()->json(['message' => 'أُغلق التقييم. شكراً لمشاركتك.', 'closed' => true], 423));
        }
    }

    private function validRole(Request $request, VolunteerEvaluation $evaluation): string
    {
        $roleIds = array_column($evaluation->roles ?? [], 'id');
        $data = $request->validate(
            ['role_id' => ['required', 'string', 'in:' . implode(',', $roleIds)]],
            ['role_id.in' => 'اختر مهمة من القائمة.', 'role_id.required' => 'اختر مهمتك.']
        );

        return $data['role_id'];
    }

    private function participant(Request $request, VolunteerEvaluation $evaluation): VolunteerEvaluationParticipant
    {
        $token = (string) $request->header(self::TOKEN_HEADER, '');
        $participant = $token === '' ? null : VolunteerEvaluationParticipant::where('token_hash', hash('sha256', $token))
            ->where('volunteer_evaluation_id', $evaluation->id)
            ->first();

        if (!$participant) {
            abort(response()->json(['message' => 'لم نجد مشاركتك. اختر مهمتك للبدء من جديد.', 'unknown' => true], 401));
        }

        return $participant;
    }

    private function mine(VolunteerEvaluationParticipant $participant): array
    {
        return [
            'role_id' => $participant->role_id,
            'answers' => (object) $participant->answers()->get()
                ->mapWithKeys(fn (VolunteerEvaluationAnswer $a) => [$a->axis_id => $a->toClient()])
                ->all(),
        ];
    }

    /** Drops empty rows and trims the texts of positives / negatives. */
    private function cleanNotes(array $notes): array
    {
        return collect($notes)
            ->map(fn ($n) => [
                'text' => trim((string) ($n['text'] ?? '')),
                'reason' => trim((string) ($n['reason'] ?? '')),
                'recommendation' => trim((string) ($n['recommendation'] ?? '')),
            ])
            ->filter(fn ($n) => $n['text'] !== '' || $n['reason'] !== '' || $n['recommendation'] !== '')
            ->values()
            ->all();
    }

    private function adminView(VolunteerEvaluation $evaluation): array
    {
        return [
            'id' => $evaluation->id,
            'event_id' => $evaluation->event_id,
            'title' => $evaluation->title,
            'roles' => $evaluation->roles ?? [],
            'axes' => $evaluation->axes ?? [],
            'recommendations' => (object) ($evaluation->recommendations ?? []),
            'session' => $evaluation->session(),
            'participants_count' => $evaluation->participants()->whereNotNull('role_id')->count(),
        ];
    }
}
