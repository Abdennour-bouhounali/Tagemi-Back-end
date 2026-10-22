<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EventReportController extends Controller
{
    /**
     * Get report for an event with statistics
     */
    public function show($eventId)
    {
        $event = Event::with([
            'report.creator', 
            'report.updater', 
            'report.approver'
        ])->findOrFail($eventId);
        
        if (!$event->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن عرض التقرير إلا للأحداث المؤرشفة'
            ], 403);
        }

        // Get event statistics to help populate the report
        $statistics = $this->getEventStatistics($event);

        return response()->json([
            'success' => true,
            'event' => $event,
            'report' => $event->report,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Create or update report
     */
    public function createOrUpdate(Request $request, $eventId)
    {
        $event = Event::findOrFail($eventId);
        
        if (!$event->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إنشاء تقرير إلا للأحداث المؤرشفة'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            // All fields are nullable
            'executive_summary' => 'nullable|string',
            'key_achievements' => 'nullable|string',
            'critical_issues' => 'nullable|string',
            
            'total_attendees' => 'nullable|integer|min:0',
            'registered_attendees' => 'nullable|integer|min:0',
            'actual_attendees' => 'nullable|integer|min:0',
            'venue_name' => 'nullable|string',
            'venue_feedback' => 'nullable|string',
            
            'total_appointments' => 'nullable|integer|min:0',
            'completed_appointments' => 'nullable|integer|min:0',
            'cancelled_appointments' => 'nullable|integer|min:0',
            'no_show_appointments' => 'nullable|integer|min:0',
            
            'total_staff' => 'nullable|integer|min:0',
            'total_volunteers' => 'nullable|integer|min:0',
            'team_performance_notes' => 'nullable|string',
            'outstanding_team_members' => 'nullable|string',
            
            'logistics_summary' => 'nullable|string',
            'transportation_notes' => 'nullable|string',
            'accommodation_notes' => 'nullable|string',
            'catering_notes' => 'nullable|string',
            'equipment_notes' => 'nullable|string',
            
            'planned_budget' => 'nullable|numeric|min:0',
            'actual_expenses' => 'nullable|numeric|min:0',
            'major_expenses' => 'nullable|string',
            'cost_saving_measures' => 'nullable|string',
            
            'marketing_channels_used' => 'nullable|string',
            'social_media_reach' => 'nullable|integer|min:0',
            'social_media_engagement' => 'nullable|integer|min:0',
            'marketing_effectiveness' => 'nullable|string',
            
            'overall_satisfaction_rating' => 'nullable|numeric|min:0|max:5',
            'positive_feedback_summary' => 'nullable|string',
            'negative_feedback_summary' => 'nullable|string',
            'attendee_suggestions' => 'nullable|string',
            
            'media_mentions' => 'nullable|integer|min:0',
            'media_outlets' => 'nullable|string',
            'media_highlights' => 'nullable|string',
            
            'sponsors_list' => 'nullable|string',
            'partnership_outcomes' => 'nullable|string',
            'sponsor_feedback' => 'nullable|string',
            
            'positive_points' => 'nullable|string',
            'negative_points' => 'nullable|string',
            'challenges_faced' => 'nullable|string',
            'solutions_implemented' => 'nullable|string',
            
            'technical_issues' => 'nullable|string',
            'technical_solutions' => 'nullable|string',
            'infrastructure_notes' => 'nullable|string',
            
            'security_measures' => 'nullable|string',
            'safety_incidents' => 'nullable|string',
            'health_protocols' => 'nullable|string',
            
            'program_highlights' => 'nullable|string',
            'speaker_performance' => 'nullable|string',
            'session_feedback' => 'nullable|string',
            
            'what_went_well' => 'nullable|string',
            'what_went_wrong' => 'nullable|string',
            'unexpected_outcomes' => 'nullable|string',
            'best_practices_identified' => 'nullable|string',
            
            'future_tasks' => 'nullable|string',
            'recommendations' => 'nullable|string',
            'improvements_needed' => 'nullable|string',
            
            'general_notes' => 'nullable|string',
            'conclusion' => 'nullable|string',
            'acknowledgments' => 'nullable|string',
            
            'assignments' => 'nullable|array',
            'assignments.*.name' => 'required|string',
            'assignments.*.phone' => 'required|string',
            'assignments.*.role' => 'nullable|string',
            'assignments.*.task' => 'required|string',
            'assignments.*.performance_notes' => 'nullable|string',
            
            'action_items' => 'nullable|array',
            'action_items.*.task' => 'required|string',
            'action_items.*.priority' => 'required|in:high,medium,low',
            'action_items.*.deadline' => 'nullable|date',
            'action_items.*.assignee' => 'nullable|string',
            
            'photo_references' => 'nullable|array',
            'document_references' => 'nullable|array',
            
            'report_status' => 'nullable|in:draft,final,approved',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطأ في البيانات المدخلة',
                'errors' => $validator->errors()
            ], 422);
        }

        $reportData = $request->except(['event_id', 'created_by', 'approved_by', 'approved_at']);

        $existingReport = EventReport::where('event_id', $eventId)->first();
        
        $report = EventReport::updateOrCreate(
            ['event_id' => $eventId],
            array_merge($reportData, [
                'created_by' => $existingReport->created_by ?? auth()->id(),
                'updated_by' => auth()->id(),
            ])
        );

        // Calculate metrics
        $report->calculateMetrics();
        $report->save();

        $report->load(['creator', 'updater', 'approver']);

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ التقرير بنجاح',
            'report' => $report
        ]);
    }

    /**
     * Approve report
     */
    public function approve($eventId)
    {
        $report = EventReport::where('event_id', $eventId)->firstOrFail();

        $report->update([
            'report_status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم اعتماد التقرير بنجاح',
            'report' => $report->load(['approver'])
        ]);
    }

    /**
     * Delete report
     */
    public function destroy($eventId)
    {
        $report = EventReport::where('event_id', $eventId)->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'التقرير غير موجود'
            ], 404);
        }

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف التقرير بنجاح'
        ]);
    }

    /**
     * Get event statistics to help with report
     */
    private function getEventStatistics($event)
    {
        $totalAppointments = $event->archivedAppointments()->count();
        // "Cancelled" in the report = booked but never came (VisitStatistics outcomes)
        $outcomes = $event->archivedAppointments()->pluck('status')
            ->map(fn ($status) => \App\Support\VisitStatistics::outcomeOf($status))
            ->countBy();
        $completedAppointments = $outcomes->get('Completed', 0);
        $cancelledAppointments = $outcomes->get('Pending', 0);

        return [
            'total_appointments' => $totalAppointments,
            'completed_appointments' => $completedAppointments,
            'cancelled_appointments' => $cancelledAppointments,
        ];
    }
}