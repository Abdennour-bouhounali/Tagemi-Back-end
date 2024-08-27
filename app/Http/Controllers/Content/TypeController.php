<?php

namespace App\Http\Controllers\Content;

use App\Models\Type;
use Illuminate\Http\Request;

class TypeController extends Controller
{
    public function index()
    {
        return Type::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'description_en' => 'nullable|string',
            'description_ar' => 'nullable|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg',
            'statistics' => 'nullable|array',
        ]);

        // Handle file upload
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $imageName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->move(public_path('uploads/types'), $imageName);
            $imageUrl = 'uploads/types/' . $imageName;
        } else {
            return response()->json(['error' => 'Image upload failed'], 422);
        }

        // Create the type
        $type = Type::create([
            'name_en' => $request->name_en,
            'name_ar' => $request->name_ar,
            'description_en' => $request->description_en,
            'description_ar' => $request->description_ar,
            'image_url' => $imageUrl,
            'statistics' => $request->statistics,
        ]);

        return response()->json($type, 201);
    }

    public function show($id)
    {
        return Type::findOrFail($id);
    }

    public function Updatetype(Request $request, $id)
    {
        // Validate the request
        $request->validate([
            'name_en' => 'required|string|max:255',
            'name_ar' => 'required|string|max:255',
            'description_en' => 'nullable|string',
            'description_ar' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
            'statistics' => 'nullable|array',
        ]);

        // Find the type by ID
        $type = Type::findOrFail($id);

        // Handle file upload if a new image is provided
        if ($request->hasFile('image')) {
            unlink($type->image_url);
            $file = $request->file('image');
            $imageName = time() . '_' . $file->getClientOriginalName();
            $file->move(public_path('uploads/types'), $imageName);
            $imageUrl = 'uploads/types/' . $imageName;

            // Update the image field
            $type->image_url = $imageUrl;
        }

        // Update other fields
        $type->name_en = $request->input('name_en');
        $type->name_ar = $request->input('name_ar');
        $type->description_en = $request->input('description_en');
        $type->description_ar = $request->input('description_ar');
        $type->statistics = $request->input('statistics');

        // Save the updated type
        $type->save();

        return response()->json($type, 200);
    }

    public function destroy(Type $type)
    {
        $type->delete();
        return response()->json(['message' => 'type deleted successfully'], 204);
    }
}
