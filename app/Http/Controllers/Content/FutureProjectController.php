<?php

namespace App\Http\Controllers\Content;

use App\Models\ProjectImage;
use Illuminate\Http\Request;
use App\Models\FutureProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class FutureProjectController extends Controller
{
    public function index()
    {
        return FutureProject::with('projectImages')->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'title_en' => 'required|string|max:255',
            'title_ar' => 'required|string|max:255',
            'description_en' => 'required|string',
            'description_ar' => 'required|string',
        ]);

        $FutureProject = FutureProject::create($request->all());
        return response()->json(['message'=>'Well done','FutureProject'=>$FutureProject], 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'title_en' => 'required|string|max:255',
            'title_ar' => 'required|string|max:255',
            'description_en' => 'required|string',
            'description_ar' => 'required|string',

        ]);

        $FutureProject = FutureProject::findOrFail($id);
        $FutureProject->update($request->all());
        return response()->json($FutureProject, 200);
    }

    public function storeProjectImages(Request $request)
    {
        // Get the uploaded files
        $pictures = $request->file('images');
    
        // Check if it's a single file or an array of files
        if (!is_array($pictures)) {
            // Convert to an array if it's a single file
            $pictures = [$pictures];
        }
    
        // Now you can iterate over the array of files
        foreach ($pictures as $picture) {
            // Generate a unique name for each image
            $imageName = time() . '_' . $request->projectId . '_' . $picture->getClientOriginalName();
    
            // Move the file to the desired location
            $filePath = $picture->move(public_path('uploads/projects'), $imageName);
    
            // Save the image information in the database
            ProjectImage::create([
                'projectId' => $request->projectId,
                'imageUrl' => 'uploads/projects/' . $imageName,
            ]);
        }
    
        return response()->json("good", 201);
        // return response()->json(['message'=>'Images uploaded successfully','project'=>$project], 201);

    }
    

    public function show($id)
    {
        return FutureProject::with('projectImages')->findOrFail($id);
    }
    

    public function delete($id)
    {
    $media = ProjectImage::find($id);


        unlink($media->imageUrl);

        $media->delete();
    
        // Delete record from database
    
        return response()->json(['message' => 'Media deleted successfully']);
    }



    public function destroy(FutureProject $futureProject)
    {

        try {
            // Start a transaction to ensure both operations succeed or fail together
            DB::beginTransaction();
            
            // Delete associated media
            ProjectImage::where('projectId', $futureProject->id)->delete();
            
            // Delete the project
            $futureProject->delete();
            
            // Commit the transaction
            DB::commit();
    
            // Return success response
            return response()->json(['message' => 'Project deleted successfully'], 204);
        } catch (\Exception $e) {
            // Rollback the transaction in case of an error
            DB::rollBack();
    
            // Return error response
            return response()->json(['error' => 'Failed to delete project'], 500);
        }


     

    }
}
