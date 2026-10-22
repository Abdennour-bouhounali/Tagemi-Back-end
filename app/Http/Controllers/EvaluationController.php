<?php

namespace App\Http\Controllers;

use App\Models\Evaluation;
use App\Models\Event;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class EvaluationController extends Controller
{
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'event_id' => 'required|exists:events,id',
        'appointment_id' => 'nullable|exists:appointments,id',
        'age_range' => 'required|in:أقل من 18 سنة,18-35 سنة,36-50 سنة,أكثر من 50 سنة',
        'gender' => 'required|in:ذكر,أنثى',
        'specialties' => 'required|array|min:1', // Changed to array
        'specialties.*' => 'string|max:100',
        'specialty_other' => 'nullable|string|max:255', // For "other" option
        'overall_organization' => 'required|integer|between:1,5',
        'medical_quality' => 'required|integer|between:1,5',
        'staff_performance' => 'required|integer|between:1,5',
        'registration_ease' => 'required|integer|between:1,5',
        'platform_organization' => 'required|integer|between:1,5',
        'suggested_specialties' => 'required|string',
        'positive_points' => 'required|string',
        'negative_points' => 'required|string',
        'suggestions' => 'required|string',
        'would_recommend' => 'required|boolean',
        'contact_name' => 'nullable|string|max:255',
        'contact_phone' => 'nullable|string|max:20',
        'contact_email' => 'nullable|email|max:255',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'خطأ في البيانات المدخلة',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // Only validated fields: is_processed / admin_notes etc. must not be settable by the public
        $data = $validator->validated();
        if (!empty($data['appointment_id'])
            && !\App\Models\Appointment::whereKey($data['appointment_id'])->where('event_id', $data['event_id'])->exists()) {
            $data['appointment_id'] = null;
        }
        // Keep first specialty in old field for backward compatibility
        $data['specialty'] = $data['specialties'][0] ?? null;
        
        $evaluation = Evaluation::create([
            ...$data,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم إرسال التقييم بنجاح. شكراً لمشاركتك!',
            'evaluation' => $evaluation
        ], 201);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'حدث خطأ أثناء حفظ التقييم',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * Get all evaluations (admin only)
     */
    public function index(Request $request)
    {
        $query = Evaluation::with(['event', 'appointment'])
            ->orderBy('submitted_at', 'desc');

        // Filters
        if ($request->has('event_id')) {
            $query->where('event_id', $request->event_id);
        }

        if ($request->has('is_processed')) {
            $query->where('is_processed', $request->boolean('is_processed'));
        }

        if ($request->has('would_recommend')) {
            $query->where('would_recommend', $request->boolean('would_recommend'));
        }

        if ($request->has('specialty')) {
            $query->where('specialty', $request->specialty);
        }

        if ($request->has('gender')) {
            $query->where('gender', $request->gender);
        }

        $perPage = $request->get('per_page', 20);
        $evaluations = $query->paginate($perPage);

        return response()->json($evaluations);
    }

    /**
     * Get single evaluation
     */
    public function show($id)
    {
        $evaluation = Evaluation::with(['event', 'appointment'])->findOrFail($id);
        return response()->json($evaluation);
    }

    /**
     * Mark evaluation as processed
     */
    public function markAsProcessed(Request $request, $id)
    {
        $evaluation = Evaluation::findOrFail($id);
        
        $evaluation->markAsProcessed($request->admin_notes);

        return response()->json([
            'message' => 'تم تحديث حالة التقييم',
            'evaluation' => $evaluation
        ]);
    }

    /**
     * Get evaluation statistics for an event
     */
    public function statistics($eventId)
    {
        $event = Event::findOrFail($eventId);
        
        $evaluations = Evaluation::where('event_id', $eventId)->get();

        if ($evaluations->isEmpty()) {
            return response()->json([
                'message' => 'لا توجد تقييمات لهذا الحدث',
                'statistics' => null
            ]);
        }

        $statistics = [
            'total_evaluations' => $evaluations->count(),
            'processed_count' => $evaluations->where('is_processed', true)->count(),
            'unprocessed_count' => $evaluations->where('is_processed', false)->count(),
            
            // Recommendation stats
            'would_recommend' => $evaluations->where('would_recommend', true)->count(),
            'would_not_recommend' => $evaluations->where('would_recommend', false)->count(),
            'recommendation_percentage' => round(($evaluations->where('would_recommend', true)->count() / $evaluations->count()) * 100, 2),
            
            // Average ratings
            'average_ratings' => [
                'overall_organization' => round($evaluations->avg('overall_organization'), 2),
                'medical_quality' => round($evaluations->avg('medical_quality'), 2),
                'staff_performance' => round($evaluations->avg('staff_performance'), 2),
                'registration_ease' => round($evaluations->avg('registration_ease'), 2),
                'platform_organization' => round($evaluations->avg('platform_organization'), 2),
                'overall_average' => round($evaluations->avg(function ($evaluation) {
                    return $evaluation->average_rating;
                }), 2),
            ],
            
            // Demographics
            'age_distribution' => $evaluations->groupBy('age_range')->map->count(),
            'gender_distribution' => $evaluations->groupBy('gender')->map->count(),
            'specialty_distribution' => $evaluations->groupBy('specialty')->map->count(),
            
            // Rating distribution
            'rating_distribution' => [
                'overall_organization' => $this->getRatingDistribution($evaluations, 'overall_organization'),
                'medical_quality' => $this->getRatingDistribution($evaluations, 'medical_quality'),
                'staff_performance' => $this->getRatingDistribution($evaluations, 'staff_performance'),
                'registration_ease' => $this->getRatingDistribution($evaluations, 'registration_ease'),
                'platform_organization' => $this->getRatingDistribution($evaluations, 'platform_organization'),
            ],
        ];

        return response()->json([
            'event' => $event,
            'statistics' => $statistics
        ]);
    }

    /**
     * Helper to get rating distribution
     */
    private function getRatingDistribution($evaluations, $field)
    {
        return [
            'ضعيف' => $evaluations->where($field, 1)->count(),
            'مقبول' => $evaluations->where($field, 2)->count(),
            'جيد' => $evaluations->where($field, 3)->count(),
            'جيد جدا' => $evaluations->where($field, 4)->count(),
            'ممتاز' => $evaluations->where($field, 5)->count(),
        ];
    }

    /**
     * Delete evaluation (admin only)
     */
    public function destroy($id)
    {
        $evaluation = Evaluation::findOrFail($id);
        $evaluation->delete();

        return response()->json([
            'message' => 'تم حذف التقييم بنجاح'
        ]);
    }

    /**
     * Get evaluation form data (public endpoint)
     */
    public function getFormData($eventId)
    {
        $event = Event::with('eventSpecialties.specialty')->findOrFail($eventId);
        
        $specialties = $event->eventSpecialties->map(function ($eventSpecialty) {
            return $eventSpecialty->specialty->name;
        })->unique()->values();

        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'date' => $event->date,
            ],
            'specialties' => $specialties,
        ]);
    }
}