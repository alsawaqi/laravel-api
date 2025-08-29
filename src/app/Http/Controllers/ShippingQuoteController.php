<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ShippingQuoteController extends Controller
{
    
   

   public function quote(Request $request): JsonResponse
{
    // 1) Validate input
    $request->validate([
        'address_id' => 'required|integer|exists:Customers_Contact_T,id',
        'items'      => 'required|array|min:1',
        'items.*.product_id' => 'required|integer|exists:Products_Master_T,id',
        'items.*.qty'        => 'required|numeric|min:0.0001',
        'include_heavy'      => 'sometimes|boolean',
    ]);

    $includeHeavy = (bool)$request->input('include_heavy', false);

    // 2) Compute totals from Products_Master_T (qty-aware)
    $items = $request->input('items', []);
    $qtyById = [];
    foreach ($items as $it) {
        $pid = (int)$it['product_id'];
        $qty = (float)$it['qty'];
        $qtyById[$pid] = ($qtyById[$pid] ?? 0) + $qty;
    }

    $productIds = array_keys($qtyById);
    $products = DB::table('Products_Master_T')
        ->whereIn('id', $productIds)
        ->select('id', 'Weight_Kg', 'Volume_Cbm')
        ->get()
        ->keyBy('id');

    $totalWeight = 0.0; // kg
    $totalVolume = 0.0; // cbm
    foreach ($qtyById as $pid => $q) {
        $p = $products[$pid] ?? null;
        if (!$p) continue;
        $totalWeight += ((float)$p->Weight_Kg   ?: 0) * $q;
        $totalVolume += ((float)$p->Volume_Cbm ?: 0) * $q;
    }

    // 3) Resolve destination names from address_id
    $addr = DB::table('Customers_Contact_T')
        ->where('id', $request->integer('address_id'))
        ->first();

    if (!$addr) {
        return response()->json(['options' => []]); // graceful fallback
    }

    $districtName = $addr->District_Id
        ? DB::table('Geox_District_Master_T')->where('id', $addr->District_Id)->value('District_Name')
        : null;
    $regionName = $addr->Region_Id
        ? DB::table('Geox_Region_Master_T')->where('id', $addr->Region_Id)->value('Region_Name')
        : null;
    $countryName = $addr->Country_Id
        ? DB::table('Geox_Country_Master_T')->where('id', $addr->Country_Id)->value('Country_Name')
        : null;

    $districtName = $districtName ? trim($districtName) : null;
    $regionName   = $regionName   ? trim($regionName)   : null;
    $countryName  = $countryName  ? trim($countryName)  : null;

    // Helper: fetch active shipper+destination pairs by a specific name level
    $fetchPairsBy = function (string $level, string $name) {
        $col = $level === 'district'
            ? 'd.Shippers_Destination_District'
            : ($level === 'region' ? 'd.Shippers_Destination_Region' : 'd.Shippers_Destination_Country');

        return DB::table('Shippers_Master_T as m')
            ->join('Shipper_Destinations_T as d', 'd.Shippers_Id', '=', 'm.id')
            ->leftJoin('Shipper_Shipping_Rates_T as r', function($j) {
                $j->on('r.Shippers_Id', '=', 'm.id')
                  ->on('r.Shippers_Destination_Id', '=', 'd.id');
            })
            ->where('m.Shippers_Is_Active', 1)
            ->whereRaw("LOWER($col) = LOWER(?)", [$name])
            ->select([
                'm.id as shipper_id',
                'm.Shippers_Name as shipper_name',
                'm.Shippers_Rate_Mode as rate_mode',   // weight|volume|both
                'd.id as destination_id',
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Volume,0) as flag_volume'),
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Weight,0) as flag_weight'),
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Applicable,1) as flag_applicable'),
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Box,0) as flag_box'),
            ])
            ->get();
    };

    // Match by district → region → country (first non-empty)
    $pairs = collect();
    if ($districtName) $pairs = $fetchPairsBy('district', $districtName);
    if ($pairs->isEmpty() && $regionName)  $pairs = $fetchPairsBy('region', $regionName);
    if ($pairs->isEmpty() && $countryName) $pairs = $fetchPairsBy('country', $countryName);

    // 4) Helpers: band fit (normalize min/max) & best band picker
    $fits = function (?float $min, ?float $max, float $value): bool {
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min]; // fix inverted data
        }
        if ($min !== null && $value < $min) return false;
        if ($max !== null && $value > $max) return false;
        return true;
    };

    $pickBand = function ($bands, float $value, string $minField, string $maxField) use ($fits) {
        $best = null; $bestMin = null;
        foreach ($bands as $b) {
            $min = is_null($b->$minField) ? null : (float)$b->$minField;
            $max = is_null($b->$maxField) ? null : (float)$b->$maxField;
            if (!$fits($min, $max, $value)) continue;

            // prefer tighter (larger) lower bound
            $curMin = $min ?? -INF;
            if ($best === null || $curMin > ($bestMin ?? -INF)) {
                $best = $b; $bestMin = $curMin;
            }
        }
        return $best;
    };

    // 5) Build options
    $options = [];

    foreach ($pairs as $p) {
        if ((int)$p->flag_applicable !== 1) continue;

        $mode = strtolower($p->rate_mode ?? 'weight');

        // Fetch bands once (we also use their presence to "auto-enable" a basis)
        $weightBands = DB::table('Shipper_Weight_Rates_T')
            ->where('Shippers_Id', (int)$p->shipper_id)
            ->where('Shippers_Destination_Id', (int)$p->destination_id)
            ->get();

       $volumeBands = DB::table('Shipper_Volume_Rates_T')
    ->where('Shippers_Id', (int)$p->shipper_id)
    ->where('Shippers_Destination_Id', (int)$p->destination_id)
    ->get();

        $hasWeightBands = $weightBands->count() > 0;
        $hasVolumeBands = $volumeBands->count() > 0;

        // ✅ Treat presence of bands as “on”, even if flags row is missing or rate_mode is restrictive.
        $allowWeight = ((int)$p->flag_applicable === 1) && $hasWeightBands;
        $allowVolume = ((int)$p->flag_applicable === 1) && $hasVolumeBands;

        // WEIGHT option
        if ($allowWeight && $totalWeight > 0) {
    $band = $pickBand($weightBands, $totalWeight, 'Shippers_Min_Weight_Kg', 'Shippers_Max_Weight_Kg');
    if ($band) {
        $std  = (float)($band->Shippers_Standard_Shipping_Weight_Rate ?? 0);
        $base = (float)($band->Shippers_Base_Fee ?? 0);
        $per  = (float)($band->Shippers_Per_Kg_Fee ?? 0);
        $flat = (float)($band->Shippers_Flat_Fee ?? 0);
        $cur  = $band->Shippers_Currency ?? 'OMR';

        $total = $std + $base + ($per * $totalWeight) + $flat;

        $options[] = [
            'shipper_id'     => (int)$p->shipper_id,
            'shipper_name'   => $p->shipper_name,
            'destination_id' => (int)$p->destination_id,
            'basis'          => 'weight',
            'total_price'    => round($total, 3),
            'currency'       => $cur,
            'breakdown'      => [
                'band_label'   => $band->Shippers_Standard_Shipping_Weight_Size,
                'standard_rate'=> $std,
                'base_fee'     => $base,
                'per_unit_fee' => $per,
                'units_used'   => round($totalWeight, 3),
                'flat_fee'     => $flat,
            ],
        ];
    }
}

// VOLUME option
if ($allowVolume && $totalVolume > 0) {
    $band = $pickBand($volumeBands, $totalVolume, 'Shippers_Min_Volume_Cbm', 'Shippers_Max_Volume_Cbm');
    if ($band) {
        $std  = (float)($band->Shippers_Standard_Shipping_Volume_Rate ?? 0);
        $base = (float)($band->Shippers_Base_Fee ?? 0);
        $per  = (float)($band->Shippers_Per_Cbm_Fee ?? 0);
        $flat = (float)($band->Shippers_Flat_Fee ?? 0);
        $cur  = $band->Shippers_Currency ?? 'OMR';

        $total = $std + $base + ($per * $totalVolume) + $flat;

        $options[] = [
            'shipper_id'     => (int)$p->shipper_id,
            'shipper_name'   => $p->shipper_name,
            'destination_id' => (int)$p->destination_id,
            'basis'          => 'volume',
            'total_price'    => round($total, 3),
            'currency'       => $cur,
            'breakdown'      => [
                'band_label'   => $band->Shippers_Standard_Shipping_Volume_Size,
                'standard_rate'=> $std,
                'base_fee'     => $base,
                'per_unit_fee' => $per,
                'units_used'   => round($totalVolume, 4),
                'flat_fee'     => $flat,
            ],
        ];
    }
}

        // (Optional) Heavy logic can go here when needed, guarded by $includeHeavy.
    }

    // 6) Sort cheapest first; return what the UI needs
    usort($options, fn($a,$b) => $a['total_price'] <=> $b['total_price']);

    return response()->json([
         'totals' => [
        'weight_kg'  => round($totalWeight, 3),
        'volume_cbm' => round($totalVolume, 4),
    ],
        'options' => array_map(function ($opt) {
            return [
                'shipper_id'     => $opt['shipper_id'],
                'shipper_name'   => $opt['shipper_name'],
                'basis'          => $opt['basis'],       // 'weight' | 'volume'
                'total_price'    => $opt['total_price'],
                'currency'       => $opt['currency'],
                'breakdown'      => $opt['breakdown'],
                'destination_id' => $opt['destination_id'],
            ];
        }, array_values($options)),
    ]);
}


 

 

   
}
