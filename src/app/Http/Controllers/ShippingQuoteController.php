<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ShippingQuoteController extends Controller
{
   
    public function quote(Request $request): JsonResponse
    {
        // 1) validate
        $request->validate([
            'address_id' => 'nullable|integer|exists:Customers_Contact_T,id',

            'destination' => 'nullable|array',
            'destination.country_id'  => 'nullable|integer',
            'destination.region_id'   => 'nullable|integer',
            'destination.district_id' => 'nullable|integer',
            'destination.country'     => 'nullable|string',
            'destination.region'      => 'nullable|string',
            'destination.district'    => 'nullable|string',

            'totals' => 'required|array',
            'totals.weight_kg' => 'nullable|numeric|min:0',
            'totals.volume_cbm'=> 'nullable|numeric|min:0',
            'include_heavy'    => 'nullable|boolean',
        ]);

        $weight = (float) ($request->input('totals.weight_kg') ?? 0);
        $volume = (float) ($request->input('totals.volume_cbm') ?? 0);
        $includeHeavy = (bool) $request->input('include_heavy', false);

        // 2) Resolve destination: prefer address_id → IDs → names
        $dest = [
            'country_id'  => $request->input('destination.country_id'),
            'region_id'   => $request->input('destination.region_id'),
            'district_id' => $request->input('destination.district_id'),
            'country'     => $request->input('destination.country'),
            'region'      => $request->input('destination.region'),
            'district'    => $request->input('destination.district'),
        ];

        if ($request->filled('address_id')) {
            $addr = DB::table('Customers_Contact_T')->where('id', $request->integer('address_id'))->first();
            if ($addr) {
                // Prefer IDs from address
                $dest['country_id']  = $addr->Country_Id  ?? $dest['country_id'];
                $dest['region_id']   = $addr->Region_Id   ?? $dest['region_id'];
                $dest['district_id'] = $addr->District_Id ?? $dest['district_id'];

                // Optional: translate IDs to names for string matching
                if ($dest['country_id']) {
                    $c = DB::table('Geox_Country_Master_T')->where('id', $dest['country_id'])->value('Country_Name');
                    if ($c) $dest['country'] = $c;
                }
                if ($dest['region_id']) {
                    $r = DB::table('Geox_Region_Master_T')->where('id', $dest['region_id'])->value('Region_Name');
                    if ($r) $dest['region'] = $r;
                }
                if ($dest['district_id']) {
                    $d = DB::table('Geox_District_Master_T')->where('id', $dest['district_id'])->value('District_Name');
                    if ($d) $dest['district'] = $d;
                }
            }
        }

        // 3) Build destination WHERE (district → region → country)
        $destWhere = function($q) use ($dest) {
            $dId = $dest['district_id']; $rId = $dest['region_id']; $cId = $dest['country_id'];
            $dNm = $dest['district'];    $rNm = $dest['region'];    $cNm = $dest['country'];

            // We match free-text fields in Shipper_Destinations_T
            $q->where(function($qq) use ($dId, $rId, $cId, $dNm, $rNm, $cNm) {
                // Most specific first: district
                $qq->where(function($w) use ($dId, $dNm) {
                        if (!is_null($dId)) $w->where('Shippers_Destination_District', (string)$dId);
                        if ($dNm)           $w->orWhere('Shippers_Destination_District', $dNm);
                    })
                    // then region
                    ->orWhere(function($w) use ($rId, $rNm) {
                        if (!is_null($rId)) $w->where('Shippers_Destination_Region', (string)$rId);
                        if ($rNm)           $w->orWhere('Shippers_Destination_Region', $rNm);
                    })
                    // then country
                    ->orWhere(function($w) use ($cId, $cNm) {
                        if (!is_null($cId)) $w->where('Shippers_Destination_Country', (string)$cId);
                        if ($cNm)           $w->orWhere('Shippers_Destination_Country', $cNm);
                    });
            });
        };

        // 4) Find active shippers + destinations + flags
        $pairs = DB::table('Shippers_Master_T as m')
            ->join('Shipper_Destinations_T as d', 'd.Shippers_Id', '=', 'm.id')
            ->leftJoin('Shipper_Shipping_Rates_T as r', function($j) {
                $j->on('r.Shippers_Id', '=', 'm.id')->on('r.Shippers_Destination_Id', '=', 'd.id');
            })
            ->where('m.Shippers_Is_Active', 1)
            ->where($destWhere)
            ->select([
                'm.id as shipper_id',
                'm.Shippers_Name as shipper_name',
                'm.Shippers_Rate_Mode as rate_mode',   // weight|volume|both
                'd.id as destination_id',
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Volume,0) as flag_volume'),
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Weight,0) as flag_weight'),
                DB::raw('COALESCE(r.Shippers_Destination_Rate_Applicable,1) as flag_applicable'),
            ])
            ->get();

        $options = [];

        foreach ($pairs as $p) {
            if ((int)$p->flag_applicable !== 1) continue;

            $mode = strtolower($p->rate_mode ?? 'weight');
            $canWeight = ($mode === 'weight' || $mode === 'both') && (int)$p->flag_weight === 1;
            $canVolume = ($mode === 'volume' || $mode === 'both') && (int)$p->flag_volume === 1;

            // Weight option
            if ($canWeight && $weight > 0) {
                $band = DB::table('Shipper_Weight_Rates_T')
                    ->where('Shippers_Id', $p->shipper_id)
                    ->where('Shippers_Destination_Id', $p->destination_id)
                    ->where(function($qq) use ($weight) {
                        $qq->whereNull('Shippers_Min_Weight_Kg')->orWhere('Shippers_Min_Weight_Kg', '<=', $weight);
                    })
                    ->where(function($qq) use ($weight) {
                        $qq->whereNull('Shippers_Max_Weight_Kg')->orWhere('Shippers_Max_Weight_Kg', '>=', $weight);
                    })
                    ->orderByRaw('COALESCE(Shippers_Min_Weight_Kg, 0) ASC')
                    ->first();

                if ($band) {
                    $std  = (float)($band->Shippers_Standard_Shipping_Weight_Rate ?? 0);
                    $base = (float)($band->Shippers_Base_Fee ?? 0);
                    $per  = (float)($band->Shippers_Per_Kg_Fee ?? 0);
                    $flat = (float)($band->Shippers_Flat_Fee ?? 0);
                    $cur  = $band->Shippers_Currency ?? 'OMR';

                    $total = $std + $base + ($per * $weight) + $flat;

                    $options[] = [
                        'shipper_id'     => $p->shipper_id,
                        'shipper_name'   => $p->shipper_name,
                        'destination_id' => $p->destination_id,
                        'basis'          => 'weight',
                        'total_price'    => round($total, 3),
                        'currency'       => $cur,
                        'breakdown'      => [
                            'band_label'   => $band->Shippers_Standard_Shipping_Weight_Size,
                            'standard_rate'=> $std,
                            'base_fee'     => $base,
                            'per_unit_fee' => $per,
                            'units_used'   => round($weight, 3),
                            'flat_fee'     => $flat
                        ]
                    ];
                }
            }

            // Volume option
            if ($canVolume && $volume > 0) {
                $band = DB::table('Shipper_Volume_Rates_T')
                    ->where('Shippers_Id', $p->shipper_id)
                    ->where('Shippers_Destination_Id', $p->destination_id)
                    ->where(function($qq) use ($volume) {
                        $qq->whereNull('Shippers_Min_Volume_Cbm')->orWhere('Shippers_Min_Volume_Cbm', '<=', $volume);
                    })
                    ->where(function($qq) use ($volume) {
                        $qq->whereNull('Shippers_Max_Volume_Cbm')->orWhere('Shippers_Max_Volume_Cbm', '>=', $volume);
                    })
                    ->orderByRaw('COALESCE(Shippers_Min_Volume_Cbm, 0) ASC')
                    ->first();

                if ($band) {
                    $std  = (float)($band->Shippers_Standard_Shipping_Volume_Rate ?? 0);
                    $base = (float)($band->Shippers_Base_Fee ?? 0);
                    $per  = (float)($band->Shippers_Per_Cbm_Fee ?? 0);
                    $flat = (float)($band->Shippers_Flat_Fee ?? 0);
                    $cur  = $band->Shippers_Currency ?? 'OMR';

                    $total = $std + $base + ($per * $volume) + $flat;

                    $options[] = [
                        'shipper_id'     => $p->shipper_id,
                        'shipper_name'   => $p->shipper_name,
                        'destination_id' => $p->destination_id,
                        'basis'          => 'volume',
                        'total_price'    => round($total, 3),
                        'currency'       => $cur,
                        'breakdown'      => [
                            'band_label'   => $band->Shippers_Standard_Shipping_Volume_Size,
                            'standard_rate'=> $std,
                            'base_fee'     => $base,
                            'per_unit_fee' => $per,
                            'units_used'   => round($volume, 4),
                            'flat_fee'     => $flat
                        ]
                    ];
                }
            }

            // Heavy (optional): add when you’re ready
        }

        usort($options, fn($a,$b) => $a['total_price'] <=> $b['total_price']);

        return response()->json(['options' => array_values($options)]);
    }
}
