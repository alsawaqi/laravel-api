<?php

namespace App\Services;

use App\Models\ProductVendorOffer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class VendorOffers
{
    public const FIELDS = ['Vendor_Id', 'Product_Price', 'Product_Cost', 'Minimum_Selling_Price', 'Product_Stock', 'Status', 'Commission_Type', 'Commission_Value'];

    public static function ready(): bool
    {
        return Schema::hasTable('Products_Vendor_Offers_T');
    }

    /** Read projection only. All writes must target the offer explicitly. */
    public static function products(string $model, ?int $vendorId = null)
    {
        if (! self::ready()) {
            return $model::query()->when($vendorId, fn ($q) => $q->where('Vendor_Id', $vendorId));
        }
        $projection = DB::table('Products_Master_T as p')
            ->join('Products_Vendor_Offers_T as o', 'o.Products_Id', '=', 'p.id')
            ->join('Vendors_Master_T as v', 'v.id', '=', 'o.Vendor_Id');
        $grammar = DB::connection()->getQueryGrammar();
        foreach (Schema::getColumnListing('Products_Master_T') as $column) {
            if ($column === 'deleted_at') {
                $projection->addSelect(DB::raw('COALESCE(p.deleted_at, o.deleted_at) as deleted_at'));
                continue;
            }
            if ($column === 'Is_Active') {
                $projection->addSelect(DB::raw('CASE WHEN p.Is_Active = 1 AND o.Is_Active = 1 THEN 1 ELSE 0 END as Is_Active'));
                continue;
            }
            $source = in_array($column, self::FIELDS, true) ? 'o' : 'p';
            $projection->addSelect(DB::raw($grammar->wrap("$source.$column").' as '.$grammar->wrap($column)));
        }
        $projection->addSelect('o.id as Vendor_Offer_Id', 'o.Is_Active as Offer_Is_Active', 'v.Vendor_Name as Seller_Name');
        if ($vendorId) {
            $projection->where('o.Vendor_Id', $vendorId);
        }

        return $model::query()->fromSub($projection, 'Products_Master_T');
    }

    public static function forVendor(int $productId, int $vendorId, bool $lock = false): ProductVendorOffer
    {
        return ProductVendorOffer::query()->where('Products_Id', $productId)->where('Vendor_Id', $vendorId)
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public static function owns(int $productId, int $vendorId): bool
    {
        return self::ready()
            ? ProductVendorOffer::where('Products_Id', $productId)->where('Vendor_Id', $vendorId)->exists()
            : DB::table('Products_Master_T')->where('id', $productId)->where('Vendor_Id', $vendorId)->exists();
    }

    /** A null offer is the platform's own listing, or a pre-migration legacy owner. */
    public static function resolve(Model $product, ?int $offerId, bool $lock = false): Model
    {
        if (! self::ready()) {
            if ($offerId) {
                throw ValidationException::withMessages(['vendor_offer_id' => 'Vendor offers are not available yet.']);
            }

            return clone $product;
        }
        if (! $offerId && ! $product->Vendor_Id) {
            return clone $product;
        }
        $offer = ProductVendorOffer::query()->where('Products_Id', $product->id)
            ->when($offerId, fn ($q) => $q->whereKey($offerId), fn ($q) => $q->where('Vendor_Id', $product->Vendor_Id))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $offer || ! $offer->Is_Active || $offer->Status === 'discontinued') {
            throw ValidationException::withMessages(['vendor_offer_id' => 'This seller offer is no longer available. Choose another offer.']);
        }
        $vendor = DB::table('Vendors_Master_T')->where('id', $offer->Vendor_Id)->first();
        if (! $vendor || (isset($vendor->Is_Active) && ! $vendor->Is_Active)) {
            throw ValidationException::withMessages(['vendor_offer_id' => 'This seller is no longer available.']);
        }

        return self::overlay($product, $offer, $vendor->Vendor_Name ?? 'Vendor');
    }

    public static function overlay(Model $product, ProductVendorOffer $offer, string $seller): Model
    {
        $result = clone $product;
        foreach (self::FIELDS as $field) {
            $result->setAttribute($field, $offer->getAttribute($field));
        }
        $result->setAttribute('Vendor_Offer_Id', (int) $offer->id);
        $result->setAttribute('Seller_Name', $seller);
        $result->setAttribute('Offer_Is_Active', $offer->Is_Active);
        $result->setRelation('bulkPrices', $offer->bulkPrices);

        return $result;
    }

    /** Expand catalogue identities into independent seller offers without duplicating masters. */
    public static function expand(\Illuminate\Support\Collection $products): \Illuminate\Support\Collection
    {
        if (! self::ready() || $products->isEmpty()) {
            return $products;
        }
        $offers = ProductVendorOffer::whereIn('Products_Id', $products->pluck('id'))
            ->where('Is_Active', true)->where('Status', '<>', 'discontinued')->with('bulkPrices')->orderBy('id')->get();
        $vendors = DB::table('Vendors_Master_T')->whereIn('id', $offers->pluck('Vendor_Id'))->get()->keyBy('id');
        $byProduct = $offers->groupBy('Products_Id');

        return $products->flatMap(function ($product) use ($vendors, $byProduct) {
            $listings = collect();
            if (! $product->Vendor_Id) {
                $own = clone $product;
                $own->setAttribute('Vendor_Offer_Id', null);
                $own->setAttribute('Seller_Name', 'ISC');
                $listings->push($own);
            }
            foreach ($byProduct->get($product->id, []) as $offer) {
                $vendor = $vendors->get($offer->Vendor_Id);
                if ($vendor && (! isset($vendor->Is_Active) || $vendor->Is_Active)) {
                    $listings->push(self::overlay($product, $offer, $vendor->Vendor_Name));
                }
            }

            return $listings;
        })->values();
    }

    /** Order lines retain the selected offer even after prices or availability change. */
    public static function stockRecord(int $productId, ?int $offerId, ?int $vendorId, bool $lock = true)
    {
        if (self::ready() && ($offerId || $vendorId)) {
            $query = DB::table('Products_Vendor_Offers_T')->where('Products_Id', $productId);
            $offerId ? $query->where('id', $offerId) : $query->where('Vendor_Id', $vendorId);
            if ($offerId && $vendorId) { $query->where('Vendor_Id', $vendorId); }
        } else {
            $query = DB::table('Products_Master_T')->where('id', $productId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }
}
