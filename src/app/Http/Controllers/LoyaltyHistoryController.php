<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LoyaltyHistory;
use Illuminate\Support\Facades\DB;

class LoyaltyHistoryController extends Controller
{
    //
   public function index(Request $request)
{
    $user = $request->user();

    if (!$user || !$user->customers) {
        return response()->json([
            'data' => [],
            'pagination' => null,
            'total_points' => 0,
            'total_earned' => 0,
            'total_redeemed' => 0,
            'total_redeemed_amount' => 0,
        ]);
    }

    $request->validate([
        'page'     => ['nullable', 'integer', 'min:1'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
    ]);

    $perPage = (int) ($request->input('per_page', 10));
    $perPage = max(1, min($perPage, 50));

    $customerId = $user->customers->id;

    // ✅ Window function calculates running balance correctly for every row
    $query = LoyaltyHistory::query()
        ->where('Customer_Id', $customerId)
        ->select('*')
        ->selectRaw("
            SUM(COALESCE(Points_Earned,0) - COALESCE(Points_Redeemed,0))
            OVER (PARTITION BY Customer_Id ORDER BY created_at, id) AS Balance_After
        ")
        ->orderBy('created_at', 'desc')
        ->orderBy('id', 'desc');

    $p = $query->paginate($perPage);

    $loyalty = DB::table('Customers_Loyalty_T')
        ->where('Customer_Id', $customerId)
        ->first();
    $totalEarned = (int) ($loyalty->Points_Earned ?? 0);
    $totalRedeemed = (int) ($loyalty->Points_Redeemed ?? 0);
    $totalPoints = max(0, $totalEarned - $totalRedeemed);

    $summary = LoyaltyHistory::where('Customer_Id', $customerId)
        ->selectRaw('
            SUM(COALESCE(Points_Earned,0)) as total_earned,
            SUM(COALESCE(Points_Redeemed,0)) as total_redeemed,
            SUM(COALESCE(Redeemed_Amount,0)) as total_redeemed_amount
        ')
        ->first();

    return response()->json([
        'data' => $p->items(),
        'pagination' => [
            'current_page' => $p->currentPage(),
            'last_page'    => $p->lastPage(),
            'per_page'     => $p->perPage(),
            'total'        => $p->total(),
            'from'         => $p->firstItem(),
            'to'           => $p->lastItem(),
        ],
        'total_points' => $totalPoints,
        'total_earned' => $totalEarned,
        'total_redeemed' => $totalRedeemed,
        'total_redeemed_amount' => (float) ($summary->total_redeemed_amount ?? 0),
    ]);
}
}
