<?php

namespace App\Http\Controllers;

use App\Models\Title;
use Illuminate\Support\Facades\Schema;

class TitlesController extends Controller
{
    // GET /api/titles — public lookup for the storefront address form.
    // hasTable-guarded: returns an empty array until the isc-admin-api
    // Titles_Master_T migration has been deployed.
    public function index()
    {
        if (!Schema::hasTable('Titles_Master_T')) {
            return response()->json([]);
        }

        $titles = Title::query()
            ->where('Is_Active', 1)
            ->orderBy('Title_Name')
            ->get(['id', 'Title_Name', 'Title_Name_Ar']);

        return response()->json($titles);
    }
}
