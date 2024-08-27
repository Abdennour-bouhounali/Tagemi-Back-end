<?php

namespace App\Http\Controllers\Content;

use App\Models\Blog;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function index()
    {
        return Blog::all();
    }

    public function store(Request $request)
    {
        $blog = Blog::create($request->all());
        return response()->json($blog, 201);
    }


    public function show($id)
    {
        return Blog::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $blog = Blog::findOrFail($id);
        $blog->update($request->all());
        return response()->json($blog, 200);
    }

    public function delete($id)
    {
        Blog::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}

