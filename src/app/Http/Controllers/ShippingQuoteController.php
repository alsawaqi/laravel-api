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

        // 3) Get address IDs (no more name lookups)
        $addr = DB::table('Customers_Contact_T')
            ->where('id', $request->integer('address_id'))
            ->first();
        if (!$addr) {
            return response()->json(['options' => []]);
        }

        $districtId = $addr->District_Id ? (int)$addr->District_Id : null;
        $regionId   = $addr->Region_Id   ? (int)$addr->Region_Id   : null;
        $countryId  = $addr->Country_Id  ? (int)$addr->Country_Id  : null;

        // Helper: fetch active shipper+destination pairs by a specific *ID* level
        $fetchPairsById = function (string $level, int $id) {
            $col = $level === 'district'
                ? 'd.Shippers_Destination_District_Id'
                : ($level === 'region' ? 'd.Shippers_Destination_Region_Id' : 'd.Shippers_Destination_Country_Id');

            return DB::table('Shippers_Master_T as m')
                ->join('Shipper_Destinations_T as d', 'd.Shippers_Id', '=', 'm.id')
                ->leftJoin('Shipper_Shipping_Rates_T as r', function ($j) {
                    $j->on('r.Shippers_Id', '=', 'm.id')
                        ->on('r.Shippers_Destination_Id', '=', 'd.id');
                })
                ->where('m.Shippers_Is_Active', 1)
                ->where($col, '=', $id)
                ->select([
                    'm.id as shipper_id',
                    'm.Shippers_Name as shipper_name',
                    'm.Shippers_Rate_Mode as rate_mode',   // weight|volume|both (we still read it)
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
        if ($districtId) $pairs = $fetchPairsById('district', $districtId);
        if ($pairs->isEmpty() && $regionId)  $pairs = $fetchPairsById('region', $regionId);
        if ($pairs->isEmpty() && $countryId) $pairs = $fetchPairsById('country', $countryId);

        // 4) Helpers: band fit & total calculator (mirrors UI math)
        $fits = function (?float $min, ?float $max, float $value): bool {
            if ($min !== null && $max !== null && $min > $max) {
                [$min, $max] = [$max, $min];
            }
            if ($min !== null && $value < $min) return false;
            if ($max !== null && $value > $max) return false;
            return true;
        };

        $pickBand = function ($bands, float $value, string $minField, string $maxField) use ($fits) {
            $best = null;
            $bestMin = null;
            foreach ($bands as $b) {
                $min = is_null($b->$minField) ? null : (float)$b->$minField;
                $max = is_null($b->$maxField) ? null : (float)$b->$maxField;
                if (!$fits($min, $max, $value)) continue;

                $curMin = $min ?? -INF;
                if ($best === null || $curMin > ($bestMin ?? -INF)) {
                    $best = $b;
                    $bestMin = $curMin;
                }
            }
            return $best;
        };

        $calcTotalForBand = function ($band, float $units, string $minField, string $maxField, string $perField) {
            $std  = (float)($band->Shippers_Standard_Shipping_Weight_Rate
                ?? $band->Shippers_Standard_Shipping_Volume_Rate
                ?? 0);
            $base = (float)($band->Shippers_Base_Fee ?? 0);
            $per  = (float)($band->$perField ?? 0);
            $flat = (float)($band->Shippers_Flat_Fee ?? 0);

            $min  = isset($band->$minField) ? (float)$band->$minField : 0.0;
            $max  = isset($band->$maxField) ? (float)$band->$maxField : $units;

            // clamp units to [min, max]
            $chargeUnits = max($min, min($units, $max));

            // mirror UI: base + max(0, chargeUnits - min) * per + std + flat
            $variable = $per > 0 ? max(0.0, $chargeUnits - $min) * $per : 0.0;

            return $std + $base + $variable + $flat;
        };

        // 5) Build options
        $options = [];

        foreach ($pairs as $p) {
            if ((int)$p->flag_applicable !== 1) continue;

            $shipperId = (int)$p->shipper_id;
            $destId    = (int)$p->destination_id;

            // Bands
            $weightBands = DB::table('Shipper_Weight_Rates_T')
                ->where('Shippers_Id', $shipperId)
                ->where('Shippers_Destination_Id', $destId)
                ->get();

            $volumeBands = DB::table('Shipper_Volume_Rates_T')
                ->where('Shippers_Id', $shipperId)
                ->where('Shippers_Destination_Id', $destId)
                ->get();

            $hasWeightBands = $weightBands->count() > 0;
            $hasVolumeBands = $volumeBands->count() > 0;

            // Volumetric rule (if exists & enabled) → chargeable kg
            $vr = DB::table('Shipper_Volumetric_Rules_T')
                ->where('Shippers_Id', $shipperId)
                ->where('Shippers_Destination_Id', $destId)
                ->first();

            $vrEnabled = $vr ? ((int)($vr->Enabled ?? 1) === 1) : false;
            $divisor   = $vr ? max(1.0, (float)($vr->Divisor ?? 4000)) : 4000.0;

            // Convert CBM → cm^3 and apply divisor to get volumetric-kg
            $volumetricKg = $vrEnabled ? (($totalVolume * 1_000_000.0) / $divisor) : null;
            $chargeableKg = $vrEnabled && $volumetricKg !== null
                ? max($totalWeight, $volumetricKg)
                : $totalWeight;

            // Allowances (presence of bands governs)
            $allowWeight = $hasWeightBands;
            $allowVolume = $hasVolumeBands;

            // WEIGHT option (uses chargeable kg when VR enabled)
            if ($allowWeight && $chargeableKg > 0) {
                $band = $pickBand($weightBands, $chargeableKg, 'Shippers_Min_Weight_Kg', 'Shippers_Max_Weight_Kg');
                if ($band) {
                    $total = $calcTotalForBand($band, $chargeableKg, 'Shippers_Min_Weight_Kg', 'Shippers_Max_Weight_Kg', 'Shippers_Per_Kg_Fee');
                    $cur   = $band->Shippers_Currency ?? 'OMR';

                    $options[] = [
                        'shipper_id'     => $shipperId,
                        'shipper_name'   => $p->shipper_name,
                        'destination_id' => $destId,
                        'basis'          => 'weight',
                        'total_price'    => round($total, 3),
                        'currency'       => $cur,
                        'breakdown'      => [
                            'band_label'     => $band->Shippers_Standard_Shipping_Weight_Size,
                            'units_used'     => round($chargeableKg, 3),
                            'gross_kg'       => round($totalWeight, 3),
                            'volumetric_kg'  => $volumetricKg !== null ? round($volumetricKg, 3) : null,
                        ],
                    ];
                }
            }

            // VOLUME option (pure CBM against volume bands)
            if ($allowVolume && $totalVolume > 0) {
                $band = $pickBand($volumeBands, $totalVolume, 'Shippers_Min_Volume_Cbm', 'Shippers_Max_Volume_Cbm');
                if ($band) {
                    $total = $calcTotalForBand($band, $totalVolume, 'Shippers_Min_Volume_Cbm', 'Shippers_Max_Volume_Cbm', 'Shippers_Per_Cbm_Fee');
                    $cur   = $band->Shippers_Currency ?? 'OMR';

                    $options[] = [
                        'shipper_id'     => $shipperId,
                        'shipper_name'   => $p->shipper_name,
                        'destination_id' => $destId,
                        'basis'          => 'volume',
                        'total_price'    => round($total, 3),
                        'currency'       => $cur,
                        'breakdown'      => [
                            'band_label'   => $band->Shippers_Standard_Shipping_Volume_Size,
                            'units_used'   => round($totalVolume, 4),
                        ],
                    ];
                }
            }

            // (Optional) Heavy logic (guard with $includeHeavy) …
        }

        // 6) Sort cheapest first and return
        usort($options, fn($a, $b) => $a['total_price'] <=> $b['total_price']);

        return response()->json([
            'totals' => [
                'weight_kg'        => round($totalWeight, 3),
                'volume_cbm'       => round($totalVolume, 4),
                'volumetric_note'  => 'Chargeable kg uses volumetric rule where enabled.',
            ],
            'options' => array_map(function ($opt) {
                return [
                    'shipper_id'     => $opt['shipper_id'],
                    'shipper_name'   => $opt['shipper_name'],
                    'basis'          => $opt['basis'],   // 'weight' | 'volume'
                    'total_price'    => $opt['total_price'],
                    'currency'       => $opt['currency'],
                    'breakdown'      => $opt['breakdown'],
                    'destination_id' => $opt['destination_id'],
                ];
            }, array_values($options)),
        ]);
    }
}
