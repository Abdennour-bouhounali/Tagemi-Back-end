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

    public function index(Request $request)
    {
        $query = Activity::with(['media', 'type', 'creator', 'updater']);

        if ($request->has('status')) {
            $query->status($request->status);
        }

        if ($request->has('featured') && $request->featured) {
            $query->featured();
        }

        if ($request->has('type_id')) {
            $query->byType($request->type_id);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        if ($request->has('per_page')) {
            $activities = $query->paginate($request->per_page);
        } else {
            $activities = $query->get();
        }

        return response()->json($activities);
    }

    public function showByType($typeId)
    {
        $activities = Activity::with(['media', 'type', 'creator', 'updater'])
            ->byType($typeId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($activities);
    }

    public function show($id)
    {
        $activity = Activity::with(['media', 'type', 'creator', 'updater'])->findOrFail($id);
        
        $activity->media_stats = $this->mediaService->getMediaStats($activity);
        
        return response()->json($activity);
    }

public function store(Request $request)
{
    \Log::info('Store Activity called', ['request' => $request->all()]);

    $validator = Validator::make($request->all(), [
        'type_id' => 'required|exists:types,id',
        'title_en' => 'required|string|max:255',
        'title_ar' => 'required|string|max:255',
        'description_en' => 'nullable|string',
        'description_ar' => 'nullable|string',
        'location' => 'nullable|string|max:255',
        'event_date' => 'nullable|date',
        'event_end_date' => 'nullable|date|after_or_equal:event_date',
        'status' => 'required|in:upcoming,ongoing,completed,cancelled',
        'participants_count' => 'nullable|integer|min:0',
        'beneficiaries_count' => 'nullable|integer|min:0',
        'featured' => 'nullable|boolean',
        'media.*' => 'nullable|file|mimes:jpeg,jpg,png,gif,webp,mp4,avi,mov,wmv|max:51200',
        'media_types' => 'nullable|array',
    ]);

    if ($validator->fails()) {
        \Log::warning('Validation failed', [
            'errors' => $validator->errors()->toArray()
        ]);

        return response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        \Log::info('Starting transaction');

        DB::beginTransaction();

        \Log::info('Creating activity...');
        $activity = Activity::create([
            'type_id' => $request->type_id,
            'title_en' => $request->title_en,
            'title_ar' => $request->title_ar,
            'description_en' => $request->description_en,
            'description_ar' => $request->description_ar,
            'location' => $request->location,
            'event_date' => $request->event_date,
            'event_end_date' => $request->event_end_date,
            'status' => $request->status,
            'participants_count' => $request->participants_count ?? 0,
            'beneficiaries_count' => $request->beneficiaries_count ?? 0,
            'featured' => filter_var($request->featured, FILTER_VALIDATE_BOOLEAN),
        ]);

        \Log::info('Activity created', ['activity_id' => $activity->id]);

        if ($request->hasFile('media')) {
            \Log::info('Media files detected', [
                'media_count' => count($request->file('media'))
            ]);

            $mediaFiles = $request->file('media');
            $mediaTypes = $request->input('media_types', []);

            $mediaArray = [];

            foreach ($mediaFiles as $index => $file) {
                \Log::info("Processing media file #{$index}", [
                    'original_name' => $file->getClientOriginalName(),
                    'type' => $mediaTypes[$index] ?? 'image',
                ]);

                $mediaArray[] = [
                    'file' => $file,
                    'type' => $mediaTypes[$index] ?? 'image'
                ];
            }

            \Log::info("Uploading media files...");
            $this->mediaService->uploadActivityMedia($activity, $mediaArray);
            \Log::info("Media upload completed.");
        } else {
            \Log::info('No media uploaded');
        }

        DB::commit();

        \Log::info('Transaction committed successfully');

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
            'event_end_date' => 'nullable|date|after_or_equal:event_date', // NEW: Added validation
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

            $activity->update([
                'type_id' => $request->type_id ?? $activity->type_id,
                'title_en' => $request->title_en ?? $activity->title_en,
                'title_ar' => $request->title_ar ?? $activity->title_ar,
                'description_en' => $request->description_en ?? $activity->description_en,
                'description_ar' => $request->description_ar ?? $activity->description_ar,
                'location' => $request->location ?? $activity->location,
                'event_date' => $request->event_date ?? $activity->event_date,
                'event_end_date' => $request->event_end_date ?? $activity->event_end_date, // NEW: Added field
                'status' => $request->status ?? $activity->status,
                'participants_count' => $request->participants_count ?? $activity->participants_count,
                'beneficiaries_count' => $request->beneficiaries_count ?? $activity->beneficiaries_count,
                'featured' => $request->has('featured') ? filter_var($request->featured, FILTER_VALIDATE_BOOLEAN) : $activity->featured,
            ]);

            if ($request->has('delete_media') && is_array($request->delete_media)) {
                $this->mediaService->deleteMedia($request->delete_media);
            }

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

    public function destroy($id)
    {
        try {
            $activity = Activity::findOrFail($id);

            DB::beginTransaction();

            $this->mediaService->deleteActivityMedia($activity);

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