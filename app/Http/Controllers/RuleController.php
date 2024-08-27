<?php

namespace App\Http\Controllers;


use App\Models\Rule;
use Illuminate\Http\Request;

class RuleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Rule::all();
    }

    public function show($id)
    {
        $rule = Rule::findOrFail($id);
        return $rule;
    }

    public function store(Request $request)
    {
        $request->validate([
            'rule' => 'required|string|max:255',
        ]);

        $rule = Rule::create($request->all());
        return $rule;
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'rule' => 'required|string|max:255',
        ]);

        $rule = Rule::findOrFail($id);
        $rule->update($request->all());
        return $rule;
    }

    public function destroy($id)
    {
        $rule = Rule::findOrFail($id);
        $rule->delete();
        return response()->json(null, 204);
    }
}
