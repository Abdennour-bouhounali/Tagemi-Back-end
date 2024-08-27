<?php

namespace App\Http\Controllers\Content;

use File;
use App\Models\Media;
use App\Models\Activity;
use Illuminate\Http\Request;
use \Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function index()
    {
        return Media::all();
    }

    public function mediaStore(Request $request)
    {

        $pictures = (object) $request->file('images');
            // Check if it's a single file or an array of files
        if (!is_array($pictures)) {
            // Convert to an array if it's a single file
            $pictures = [$pictures];
        }
        foreach ($pictures as  $picture) {
            
            $file = $picture;
            $imageName = time() . '_' . $request->activityId . '_' . $file->getClientOriginalName();
            
            // return $imageName;
            $filePath = $file->move(public_path('uploads/activities'), $imageName);
            
             $media = Media::create([
                 'activity_id' => $request->activityId,
                 'type' => 'image',
                 'url' => 'uploads/activities/' .$imageName ,
             ]);    
        
        }
        return response()->json("good", 201);
    }
    
    
    //     $request->validate([
    //         'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Validate image file
    //         'activity_id' => 'required|exists:activities,id',
    //     ]);

    //     $file = $request->file('image');
    //     $filePath = $file->store('uploads/activities', 'public');

    //     $media = Media::create([
    //         'url' => $filePath,
    //         'activity_id' => $request->input('activity_id'),
    //         'type'=> 'image'
    //     ]);

    //     return response()->json($media, 201);
    
    // }

    public function show($id)
    {
        return Media::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $media = Media::findOrFail($id);
        $media->update($request->all());
        return response()->json($media, 200);
    }

    public function destroy($id)
    {
    $media = Media::find($id);


        unlink($media->url);

        $media->delete();
    
        // Delete record from database
    
        return response()->json(['message' => 'Media deleted successfully']);
    }
}
