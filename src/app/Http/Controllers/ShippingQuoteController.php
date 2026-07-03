<?php

namespace App\Http\Controllers;

use App\Models\Shippers;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShippingQuoteController extends Controller
{

   public function index(Request $request): JsonResponse
   {
        return response()->json(['data' => Shippers::find($request->shipping_id)], 200);
   }

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
        // Raw query (SoftDeletes trait does not apply): exclude deleted and
        // deactivated products so quotes only price purchasable items. Both
        // filters are guarded — the columns may not exist yet on prod.
        $products = DB::table('Products_Master_T')
            ->whereIn('id', $productIds)
            ->when(
                Schema::hasColumn('Products_Master_T', 'deleted_at'),
                fn ($query) => $query->whereNull('deleted_at')
            )
            ->when(
                Schema::hasColumn('Products_Master_T', 'Is_Active'),
                fn ($query) => $query->where('Is_Active', 1)
            )
            ->select('id', 'Product_Name', 'Weight_Kg', 'Length_Cm', 'Width_Cm', 'Height_Cm', 'Volume_Cbm')
            ->get()
            ->keyBy('id');

        $totalWeight = 0.0; // kg
        $totalVolume = 0.0; // cbm
        foreach ($qtyById as $pid => $q) {
            $p = $products[$pid] ?? null;
            if (!$p) continue;
            $totalWeight += ((float)$p->Weight_Kg   ?: 0) * $q;

            $productVolume = (float)($p->Volume_Cbm ?? 0);
            if ($productVolume <= 0) {
                $length = (float)($p->Length_Cm ?? 0);
                $width = (float)($p->Width_Cm ?? 0);
                $height = (float)($p->Height_Cm ?? 0);
                $productVolume = ($length > 0 && $width > 0 && $height > 0)
                    ? $length * $width * $height
                    : 0;
            }

            $totalVolume += $productVolume * $q;
        }

        // 3) Get address IDs (no more name lookups)
        $addr = DB::table('Customers_Contact_T')
            ->where('id', $request->integer('address_id'))
            ->first();
        if (!$addr) {
            return response()->json(['options' => []]);
        }

        $location = $this->resolveAddressLocation($addr);
        $districtId = $location['district_id'];
        $regionId = $location['region_id'];
        $countryId = $location['country_id'];

        if (!$districtId && !$regionId && !$countryId) {
            return response()->json(['options' => []]);
        }

        // A destination matches only when every location level configured by the
        // admin matches the customer's address. Country-only and region-only
        // destinations still work as broader service areas.
        $rankDistrict = $districtId ?? 0;
        $rankRegion = $regionId ?? 0;
        $rankCountry = $countryId ?? 0;

        $pairs = DB::table('Shippers_Master_T as m')
            ->join('Shipper_Destinations_T as d', 'd.Shippers_Id', '=', 'm.id')
            ->leftJoin('Shipper_Shipping_Rates_T as r', function ($j) {
                $j->on('r.Shippers_Id', '=', 'm.id')
                    ->on('r.Shippers_Destination_Id', '=', 'd.id');
            })
            ->where('m.Shippers_Is_Active', 1)
            ->where(function ($q) {
                $q->whereNotNull('d.Shippers_Destination_Country_Id')
                    ->orWhereNotNull('d.Shippers_Destination_Region_Id')
                    ->orWhereNotNull('d.Shippers_Destination_District_Id');
            })
            ->where(function ($q) use ($districtId, $regionId, $countryId) {
                $q->where(function ($scope) use ($countryId) {
                    $scope->whereNull('d.Shippers_Destination_Country_Id');
                    if ($countryId !== null) {
                        $scope->orWhere('d.Shippers_Destination_Country_Id', $countryId);
                    }
                });

                $q->where(function ($scope) use ($regionId) {
                    $scope->whereNull('d.Shippers_Destination_Region_Id');
                    if ($regionId !== null) {
                        $scope->orWhere('d.Shippers_Destination_Region_Id', $regionId);
                    }
                });

                $q->where(function ($scope) use ($districtId) {
                    $scope->whereNull('d.Shippers_Destination_District_Id');
                    if ($districtId !== null) {
                        $scope->orWhere('d.Shippers_Destination_District_Id', $districtId);
                    }
                });
            })
            ->where(function ($q) use ($districtId, $regionId, $countryId) {
                if ($districtId !== null) {
                    $q->orWhere('d.Shippers_Destination_District_Id', $districtId);
                }
                if ($regionId !== null) {
                    $q->orWhere('d.Shippers_Destination_Region_Id', $regionId);
                }
                if ($countryId !== null) {
                    $q->orWhere('d.Shippers_Destination_Country_Id', $countryId);
                }
            })
            ->select([
                'm.id as shipper_id',
                'm.Shippers_Name as shipper_name',
                'm.Shippers_Image_Path as shipper_image',
                'm.Shippers_Rate_Mode as rate_mode',
                'd.id as destination_id',
                'r.id as shipping_rate_id',
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Volume,0) as flag_volume'),
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Weight,0) as flag_weight'),
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Applicable,1) as flag_applicable'),
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Box,0) as flag_box'),
                DB::raw("CASE
                    WHEN d.Shippers_Destination_District_Id = {$rankDistrict} THEN 3
                    WHEN d.Shippers_Destination_Region_Id = {$rankRegion} THEN 2
                    WHEN d.Shippers_Destination_Country_Id = {$rankCountry} THEN 1
                    ELSE 0
                END as match_rank"),
            ])
            ->orderByDesc('match_rank')
            ->get()
            ->unique('shipper_id')
            ->values();

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
            $std = $band->Shippers_Standard_Shipping_Weight_Rate
                ?? $band->Shippers_Standard_Shipping_Volume_Rate
                ?? null;
            $base = $band->Shippers_Base_Fee ?? $std ?? 0;
            $per  = (float)($band->$perField ?? 0);
            $flat = (float)($band->Shippers_Flat_Fee ?? 0);

            if ($flat > 0) {
                return $flat;
            }

            $min  = isset($band->$minField) ? (float)$band->$minField : 0.0;
            $max  = isset($band->$maxField) ? (float)$band->$maxField : $units;

            // clamp units to [min, max]
            $chargeUnits = max($min, min($units, $max));

            // Mirror admin preview: flat overrides; otherwise base/standard plus variable fee.
            $variable = $per > 0 ? max(0.0, $chargeUnits - $min) * $per : 0.0;

            return (float)$base + $variable;
        };

        $dimensionToCm = function ($value): ?float {
            if ($value === null || $value === '') return null;
            $meters = (float)$value;
            return $meters > 0 ? $meters * 100.0 : null;
        };

        $fitsCarrierMaxDimensions = function ($vr) use ($products, $qtyById, $dimensionToCm): bool {
            if (!$vr || (int)($vr->Enabled ?? 1) !== 1) {
                return true;
            }

            $maxDims = array_values(array_filter([
                isset($vr->Max_L_cm) ? (float)$vr->Max_L_cm : null,
                isset($vr->Max_W_cm) ? (float)$vr->Max_W_cm : null,
                isset($vr->Max_H_cm) ? (float)$vr->Max_H_cm : null,
            ], fn ($v) => $v !== null && $v > 0));

            if (empty($maxDims)) {
                return true;
            }

            rsort($maxDims, SORT_NUMERIC);

            foreach ($qtyById as $pid => $qty) {
                if ($qty <= 0) continue;
                $product = $products[$pid] ?? null;
                if (!$product) continue;

                $itemDims = array_values(array_filter([
                    $dimensionToCm($product->Length_Cm ?? null),
                    $dimensionToCm($product->Width_Cm ?? null),
                    $dimensionToCm($product->Height_Cm ?? null),
                ], fn ($v) => $v !== null && $v > 0));

                if (empty($itemDims)) {
                    continue;
                }

                rsort($itemDims, SORT_NUMERIC);

                foreach ($maxDims as $index => $max) {
                    if (($itemDims[$index] ?? 0) > $max) {
                        return false;
                    }
                }
            }

            return true;
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

            if (!$fitsCarrierMaxDimensions($vr)) {
                continue;
            }

            // Convert CBM → cm^3 and apply divisor to get volumetric-kg
            $volumetricKg = $vrEnabled ? (($totalVolume * 1_000_000.0) / $divisor) : null;
            $chargeableKg = $vrEnabled && $volumetricKg !== null
                ? max($totalWeight, $volumetricKg)
                : $totalWeight;

            // Existing destinations may have bands but no method row because older admin updates
            // did not persist Shipper_Shipping_Rates_T. Infer from bands in that case.
            $hasMethodFlags = !empty($p->shipping_rate_id);
            $allowWeight = $hasWeightBands && ($hasMethodFlags ? ((int)$p->flag_weight === 1) : true);
            $allowVolume = $hasVolumeBands && ($hasMethodFlags ? ((int)$p->flag_volume === 1) : true);

            // WEIGHT option (uses chargeable kg when VR enabled)
            if ($allowWeight && $chargeableKg > 0) {
                $band = $pickBand($weightBands, $chargeableKg, 'Shippers_Min_Weight_Kg', 'Shippers_Max_Weight_Kg');
                if ($band) {
                    $total = $calcTotalForBand($band, $chargeableKg, 'Shippers_Min_Weight_Kg', 'Shippers_Max_Weight_Kg', 'Shippers_Per_Kg_Fee');
                    $cur   = $band->Shippers_Currency ?? 'OMR';

                    $options[] = [
                        'shipper_id'     => $shipperId,
                        'shipper_name'   => $p->shipper_name,
                        'shipper_image'   => $p->shipper_image,
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
                        'shipper_image'   => $p->shipper_image,
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
                    'shipper_image'   => $opt['shipper_image'],
                    'basis'          => $opt['basis'],   // 'weight' | 'volume'
                    'total_price'    => $opt['total_price'],
                    'currency'       => $opt['currency'],
                    'breakdown'      => $opt['breakdown'],
                    'destination_id' => $opt['destination_id'],
                ];
            }, array_values($options)),
        ]);
    }


    public function getCodSupport(Request $request): JsonResponse
    {
        $request->validate([
            'shipper_id' => 'required|integer|exists:Shippers_Master_T,id',
        ]);

        $shipperId = $request->integer('shipper_id');

        $shipper = Shippers::find($shipperId);
        if (!$shipper) {
            return response()->json(['cod_supported' => false], 200);
        }

        $codSupported = (bool)($shipper->Shippers_COD ?? false);

        return response()->json(['cod_supported' => $codSupported], 200);
    }

    private function resolveAddressLocation(object $address): array
    {
        $districtId = $address->District_Id ? (int) $address->District_Id : null;
        $regionId = $address->Region_Id ? (int) $address->Region_Id : null;
        $countryId = $address->Country_Id ? (int) $address->Country_Id : null;

        if ($districtId !== null) {
            $district = DB::table('Geox_District_Master_T as d')
                ->leftJoin('Geox_Region_Master_T as r', 'r.id', '=', 'd.Region_Id')
                ->where('d.id', $districtId)
                ->select('d.Region_Id', 'r.Country_Id')
                ->first();

            if ($district) {
                $regionId = $district->Region_Id ? (int) $district->Region_Id : $regionId;
                $countryId = $district->Country_Id ? (int) $district->Country_Id : $countryId;
            }
        } elseif ($regionId !== null) {
            $regionCountryId = DB::table('Geox_Region_Master_T')
                ->where('id', $regionId)
                ->value('Country_Id');

            if ($regionCountryId) {
                $countryId = (int) $regionCountryId;
            }
        }

        return [
            'country_id' => $countryId,
            'region_id' => $regionId,
            'district_id' => $districtId,
        ];
    }
}
