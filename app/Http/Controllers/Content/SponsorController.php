<?php

namespace App\Http\Controllers\Content;

use App\Models\Sponsor;
use Illuminate\Http\Request;

class SponsorController extends Controller
{
    public function index()
    {
        return Sponsor::all();
    }

    public function store(Request $request)
    {
        $sponsor = Sponsor::create($request->all());
        return response()->json($sponsor, 201);
    }

    public function show($id)
    {
        return Sponsor::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $sponsor = Sponsor::findOrFail($id);
        $sponsor->update($request->all());
        return response()->json($sponsor, 200);
    }

    public function delete($id)
    {
        Sponsor::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
