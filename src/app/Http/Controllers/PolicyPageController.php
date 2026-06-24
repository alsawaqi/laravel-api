<?php

namespace App\Http\Controllers;

use App\Support\Policies\PolicyPageCatalog;

class PolicyPageController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => PolicyPageCatalog::summaries(),
        ]);
    }

    public function show(string $slug)
    {
        $page = PolicyPageCatalog::find($slug);

        if (!$page) {
            return response()->json(['message' => 'Policy page not found.'], 404);
        }

        return response()->json($page);
    }
}
