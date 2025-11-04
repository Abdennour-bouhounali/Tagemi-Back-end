<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityMedia;
use App\Models\Type;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    /**
     * Upload and store media files for an activity.
     *
     * @param Activity $activity
     * @param array $mediaArray
     * @return array
     */
    public function uploadActivityMedia(Activity $activity, array $mediaArray): array
    {
        $uploadedMedia = [];
        $type = Type::find($activity->type_id);
        $typeName = $this->sanitizeTypeName($type);

        foreach ($mediaArray as $index => $mediaData) {
            if (!isset($mediaData['file'])) {
                continue;
            }

            $file = $mediaData['file'];
            $mediaType = $mediaData['type'] ?? $this->detectMediaType($file);

            // Validate media type
            if (!$this->validateMediaType($file, $mediaType)) {
                continue;
            }

            // Upload file
            $filePath = $this->storeFile($file, $typeName, $activity->id, $index);

            // Create media record
            $media = ActivityMedia::create([
                'activity_id' => $activity->id,
                'url' => $filePath,
                'type' => $mediaType,
                'order' => $index,
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]);

            $uploadedMedia[] = $media;
        }

        return $uploadedMedia;
    }

    /**
     * Store file in the appropriate directory.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $typeName
     * @param int $activityId
     * @param int $index
     * @return string
     */
    private function storeFile($file, string $typeName, int $activityId, int $index): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = $this->generateFilename($file, $index);
        
        // Storage path: activities/{type_name}/activity_{id}/
        $storagePath = $this->getStoragePath($typeName, $activityId);

        // Ensure directory exists
        if (!Storage::disk('public')->exists($storagePath)) {
            Storage::disk('public')->makeDirectory($storagePath);
        }

        // Store file
        return $file->storeAs($storagePath, $filename, 'public');
    }

    /**
     * Generate a unique filename.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param int $index
     * @return string
     */
    private function generateFilename($file, int $index): string
    {
        $extension = $file->getClientOriginalExtension();
        $timestamp = time();
        $random = Str::random(10);
        
        return "{$timestamp}_{$index}_{$random}.{$extension}";
    }

    /**
     * Get storage path for activity media.
     *
     * @param string $typeName
     * @param int $activityId
     * @return string
     */
    private function getStoragePath(string $typeName, int $activityId): string
    {
        return "activities/{$typeName}/activity_{$activityId}";
    }

    /**
     * Delete specific media items.
     *
     * @param array $mediaIds
     * @return bool
     */
    public function deleteMedia(array $mediaIds): bool
    {
        try {
            $mediaItems = ActivityMedia::whereIn('id', $mediaIds)->get();

            foreach ($mediaItems as $media) {
                $this->deleteMediaFile($media);
                $media->delete();
            }

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to delete media: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete all media for an activity.
     *
     * @param Activity $activity
     * @return bool
     */
    public function deleteActivityMedia(Activity $activity): bool
    {
        try {
            $mediaItems = $activity->media;

            foreach ($mediaItems as $media) {
                $this->deleteMediaFile($media);
                $media->delete();
            }

            // Delete activity folder if empty
            $this->cleanupActivityFolder($activity);

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to delete activity media: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a single media file from storage.
     *
     * @param ActivityMedia $media
     * @return bool
     */
    private function deleteMediaFile(ActivityMedia $media): bool
    {
        if (Storage::disk('public')->exists($media->url)) {
            return Storage::disk('public')->delete($media->url);
        }
        return true;
    }

    /**
     * Clean up empty activity folder.
     *
     * @param Activity $activity
     * @return void
     */
    private function cleanupActivityFolder(Activity $activity): void
    {
        $type = Type::find($activity->type_id);
        $typeName = $this->sanitizeTypeName($type);
        $activityFolder = $this->getStoragePath($typeName, $activity->id);

        if (Storage::disk('public')->exists($activityFolder)) {
            $files = Storage::disk('public')->files($activityFolder);
            
            // Delete folder if empty
            if (empty($files)) {
                Storage::disk('public')->deleteDirectory($activityFolder);
                
                // Also check if type folder is empty and delete it
                $typeFolder = "activities/{$typeName}";
                $typeFolderFiles = Storage::disk('public')->allFiles($typeFolder);
                if (empty($typeFolderFiles)) {
                    Storage::disk('public')->deleteDirectory($typeFolder);
                }
            }
        }
    }

    /**
     * Sanitize type name for folder structure.
     *
     * @param Type|null $type
     * @return string
     */
    private function sanitizeTypeName(?Type $type): string
    {
        if (!$type) {
            return 'uncategorized';
        }

        return Str::slug($type->name_en ?? $type->name_ar ?? 'type_' . $type->id);
    }

    /**
     * Detect media type from file.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return string
     */
    private function detectMediaType($file): string
    {
        $mimeType = $file->getMimeType();
        
        if (Str::startsWith($mimeType, 'image/')) {
            return 'image';
        } elseif (Str::startsWith($mimeType, 'video/')) {
            return 'video';
        }
        
        return 'image'; // Default
    }

    /**
     * Validate media type.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $mediaType
     * @return bool
     */
    private function validateMediaType($file, string $mediaType): bool
    {
        $mimeType = $file->getMimeType();

        if ($mediaType === 'image') {
            return Str::startsWith($mimeType, 'image/');
        } elseif ($mediaType === 'video') {
            return Str::startsWith($mimeType, 'video/');
        }

        return false;
    }

    /**
     * Get media statistics for an activity.
     *
     * @param Activity $activity
     * @return array
     */
    public function getMediaStats(Activity $activity): array
    {
        $media = $activity->media;
        
        return [
            'total' => $media->count(),
            'images' => $media->where('type', 'image')->count(),
            'videos' => $media->where('type', 'video')->count(),
            'total_size' => $media->sum('size'),
            'formatted_size' => $this->formatBytes($media->sum('size')),
        ];
    }

    /**
     * Format bytes to human-readable format.
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Reorder media items.
     *
     * @param array $mediaOrder Array of ['id' => order]
     * @return bool
     */
    public function reorderMedia(array $mediaOrder): bool
    {
        try {
            foreach ($mediaOrder as $mediaId => $order) {
                ActivityMedia::where('id', $mediaId)->update(['order' => $order]);
            }
            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to reorder media: ' . $e->getMessage());
            return false;
        }
    }
}