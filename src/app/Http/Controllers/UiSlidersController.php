<?php

namespace App\Http\Controllers;

use App\Models\SystemParameterUiSlider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UiSlidersController extends Controller
{
    public function index()
    {
        $now = now();

        $rows = SystemParameterUiSlider::query()
            ->where('Is_Active', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('Active_From')->orWhere('Active_From', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('Active_To')->orWhere('Active_To', '>=', $now);
            })
            ->orderBy('Sort_Order')
            ->orderByDesc('id')
            ->get();

        // optional computed url:
        $rows->transform(function ($r) {
            $r->image_url = $r->Image_Path ? Storage::disk('uploads')->url($r->Image_Path) : null;
            return $r;
        });

        return response()->json($rows);
    }
}