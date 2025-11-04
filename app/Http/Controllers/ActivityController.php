<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ActivityController extends Controller
{
    protected $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Display a listing of all activities.
     */
    public function index(Request $request)
    {
        $query = Activity::with(['media', 'type']);

        // Filter by status
        if ($request->has('status')) {
            $query->status($request->status);
        }

        // Filter by featured
        if ($request->has('featured') && $request->featured) {
            $query->featured();
        }

        // Filter by type
        if ($request->has('type_id')) {
            $query->byType($request->type_id);
        }

        // Sort by
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginate or get all
        if ($request->has('per_page')) {
            $activities = $query->paginate($request->per_page);
        } else {
            $activities = $query->get();
        }

        return response()->json($activities);
    }

    /**
     * Display activities by type.
     */
    public function showByType($typeId)
    {
        $activities = Activity::with(['media', 'type'])
            ->byType($typeId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($activities);
    }

    /**
     * Display the specified activity.
     */
    public function show($id)
    {
        $activity = Activity::with(['media', 'type'])->findOrFail($id);
        
        // Add media statistics
        $activity->media_stats = $this->mediaService->getMediaStats($activity);
        
        return response()->json($activity);
    }

/**
 * Store a newly created activity in storage.
 */
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'type_id' => 'required|exists:types,id',
        'title_en' => 'required|string|max:255',
        'title_ar' => 'required|string|max:255',
        'description_en' => 'nullable|string',
        'description_ar' => 'nullable|string',
        'location' => 'nullable|string|max:255',
        'event_date' => 'nullable|date',
        'status' => 'required|in:upcoming,ongoing,completed,cancelled',
        'participants_count' => 'nullable|integer|min:0',
        'beneficiaries_count' => 'nullable|integer|min:0',
        'featured' => 'nullable|boolean',
        'media.*' => 'nullable|file|mimes:jpeg,jpg,png,gif,webp,mp4,avi,mov,wmv|max:51200',
        'media_types' => 'nullable|array',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        DB::beginTransaction();

        // Create activity
        $activity = Activity::create([
            'type_id' => $request->type_id,
            'title_en' => $request->title_en,
            'title_ar' => $request->title_ar,
            'description_en' => $request->description_en,
            'description_ar' => $request->description_ar,
            'location' => $request->location,
            'event_date' => $request->event_date,
            'status' => $request->status,
            'participants_count' => $request->participants_count ?? 0,
            'beneficiaries_count' => $request->beneficiaries_count ?? 0,
            'featured' => filter_var($request->featured, FILTER_VALIDATE_BOOLEAN),
        ]);

        // Handle media upload
        if ($request->hasFile('media')) {
            $mediaFiles = $request->file('media');
            $mediaTypes = $request->input('media_types', []);
            
            $mediaArray = [];
            foreach ($mediaFiles as $index => $file) {
                $mediaArray[] = [
                    'file' => $file,
                    'type' => $mediaTypes[$index] ?? 'image'
                ];
            }
            
            $this->mediaService->uploadActivityMedia($activity, $mediaArray);
        }

        DB::commit();

        // Load relationships
        $activity->load(['media', 'type']);

        return response()->json([
            'message' => 'Activity created successfully',
            'data' => $activity
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        
        \Log::error('Activity creation failed: ' . $e->getMessage());
        \Log::error($e->getTraceAsString());
        
        return response()->json([
            'message' => 'Failed to create activity',
            'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
        ], 500);
    }
}

/**
 * Update the specified activity in storage.
 */
public function update(Request $request, $id)
{
    $activity = Activity::findOrFail($id);

    $validator = Validator::make($request->all(), [
        'type_id' => 'sometimes|required|exists:types,id',
        'title_en' => 'sometimes|required|string|max:255',
        'title_ar' => 'sometimes|required|string|max:255',
        'description_en' => 'nullable|string',
        'description_ar' => 'nullable|string',
        'location' => 'nullable|string|max:255',
        'event_date' => 'nullable|date',
        'status' => 'sometimes|required|in:upcoming,ongoing,completed,cancelled',
        'participants_count' => 'nullable|integer|min:0',
        'beneficiaries_count' => 'nullable|integer|min:0',
        'featured' => 'nullable|boolean',
        'media.*' => 'nullable|file|mimes:jpeg,jpg,png,gif,webp,mp4,avi,mov,wmv|max:51200',
        'media_types' => 'nullable|array',
        'delete_media' => 'nullable|array',
        'delete_media.*' => 'exists:activity_media,id',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        DB::beginTransaction();

        // Update activity
        $activity->update([
            'type_id' => $request->type_id ?? $activity->type_id,
            'title_en' => $request->title_en ?? $activity->title_en,
            'title_ar' => $request->title_ar ?? $activity->title_ar,
            'description_en' => $request->description_en ?? $activity->description_en,
            'description_ar' => $request->description_ar ?? $activity->description_ar,
            'location' => $request->location ?? $activity->location,
            'event_date' => $request->event_date ?? $activity->event_date,
            'status' => $request->status ?? $activity->status,
            'participants_count' => $request->participants_count ?? $activity->participants_count,
            'beneficiaries_count' => $request->beneficiaries_count ?? $activity->beneficiaries_count,
            'featured' => $request->has('featured') ? filter_var($request->featured, FILTER_VALIDATE_BOOLEAN) : $activity->featured,
        ]);

        // Delete specified media
        if ($request->has('delete_media') && is_array($request->delete_media)) {
            $this->mediaService->deleteMedia($request->delete_media);
        }

        // Handle new media upload
        if ($request->hasFile('media')) {
            $mediaFiles = $request->file('media');
            $mediaTypes = $request->input('media_types', []);
            
            $mediaArray = [];
            foreach ($mediaFiles as $index => $file) {
                $mediaArray[] = [
                    'file' => $file,
                    'type' => $mediaTypes[$index] ?? 'image'
                ];
            }
            
            $this->mediaService->uploadActivityMedia($activity, $mediaArray);
        }

        DB::commit();

        // Load relationships
        $activity->load(['media', 'type']);

        return response()->json([
            'message' => 'Activity updated successfully',
            'data' => $activity
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        
        \Log::error('Activity update failed: ' . $e->getMessage());
        \Log::error($e->getTraceAsString());
        
        return response()->json([
            'message' => 'Failed to update activity',
            'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
        ], 500);
    }
}
    /**
     * Remove the specified activity from storage.
     */
    public function destroy($id)
    {
        try {
            $activity = Activity::findOrFail($id);

            DB::beginTransaction();

            // Delete all media files and records using MediaService
            $this->mediaService->deleteActivityMedia($activity);

            // Delete the activity
            $activity->delete();

            DB::commit();

            return response()->json([
                'message' => 'Activity deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Activity deletion failed: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to delete activity',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Reorder activity media.
     */
    public function reorderMedia(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'media_order' => 'required|array',
            'media_order.*' => 'integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $activity = Activity::findOrFail($id);

        if ($this->mediaService->reorderMedia($request->media_order)) {
            $activity->load('media');
            
            return response()->json([
                'message' => 'Media reordered successfully',
                'data' => $activity
            ]);
        }

        return response()->json([
            'message' => 'Failed to reorder media'
        ], 500);
    }
}