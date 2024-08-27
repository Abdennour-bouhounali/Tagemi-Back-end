<?php

namespace App\Http\Controllers\Content;

use Illuminate\Http\Request;
use App\Models\StaticContent;
use Illuminate\Support\Facades\Validator;

class StaticContentController extends Controller
{
    public function index()
    {
        return StaticContent::all();
    }

    public function store(Request $request)
    {
        $content = StaticContent::create($request->all());
        return response()->json($content, 201);
    }
    public function changeStatisticsLink(Request $req) {
        // Validate the request
        $validator = Validator::make($req->all(), [
            'link' => 'required|url', // Ensure 'link' is a valid URL
        ]);
    
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid URL'], 400);
        }
    
        $content = StaticContent::find(3);
    
        if (!$content) {
            return response()->json(['message' => 'Content not found'], 404);
        }
    
        $content->value = $req->link;
        $content->save();
    
        return response()->json(['message' => 'Link Changed Successfully'], 200);
    }

    public function show($id)
    {
        return StaticContent::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $content = StaticContent::findOrFail($id);
        $content->update($request->all());
        return response()->json($content, 200);
    }

    public function delete($id)
    {
        StaticContent::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
