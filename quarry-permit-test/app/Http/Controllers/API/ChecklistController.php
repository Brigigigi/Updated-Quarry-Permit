<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChecklistController extends Controller
{
    private static $checklists = []; // temporary in-memory storage

    public function save(Request $request)
    {
        $username = $request->input('username');
        if(!$username) return response()->json(['message'=>'Username required'], 400);

        $items = $request->input('items', []);
        self::$checklists[$username] = $items;

        return response()->json(['message'=>'Checklist saved', 'items'=>$items]);
    }

    public function load(Request $request)
    {
        $username = $request->query('username');
        if(!$username) return response()->json(['message'=>'Username required'], 400);

        $items = self::$checklists[$username] ?? [];
        return response()->json(['items'=>$items]);
    }
}
