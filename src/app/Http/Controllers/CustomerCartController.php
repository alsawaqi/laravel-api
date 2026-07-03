<?php

namespace App\Http\Controllers;

use App\Models\CustomerCart;
use App\Models\Products;
use Illuminate\Http\Request;
use App\Models\CustomersMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use App\Services\ProductDiscountService;
use App\Support\Pricing\BulkPriceResolver;



class CustomerCartController extends Controller
{
  private function customerOrFail()
    {
        $user = auth()->user();

        // You already have this helper
        return $user->customerOrCreate();
    }

    /**
     * A product can be carted/purchased only when it still exists (not
     * soft-deleted — SoftDeletes excludes those) and is active.
     */
    private function productAvailable(int $productId): bool
    {
        return Products::query()
            ->whereKey($productId)
            ->active()
            ->exists();
    }

    public function index()
    {
        $customer = $this->customerOrFail();
        $discountService = app(ProductDiscountService::class);

        // Bulk tiers table ships in an isc-admin-api migration — guard every
        // read so a lagging prod DB keeps today's response shape untouched.
        $hasBulkPrices = Schema::hasTable('Products_Bulk_Prices_T');

        $with = ['product' => fn ($q) => $q->withTrashed(), 'product.image'];
        if ($hasBulkPrices) {
            // Eager-load: ONE extra query for the whole cart.
            $with[] = 'product.bulkPrices';
        }

        $rows = CustomerCart::query()
            ->where('Customers_Id', $customer->id)
            // withTrashed: a soft-deleted product must still render on its
            // cart line (name/image) instead of the relation resolving null.
            ->with($with)
            ->orderBy('id', 'desc')
            ->get();

        // Lines whose product was soft-deleted or deactivated are FLAGGED,
        // not hidden: hiding them left invisible Customer_Cart_T rows that
        // still hard-blocked checkout (place() 422s on them) with nothing
        // for the customer to see or remove. The storefront can render the
        // flag and the line keeps its remove button; place() also prunes
        // these rows when it rejects, so checkout self-heals either way.
        $hasIsActive = Schema::hasColumn('Products_Master_T', 'Is_Active');

        $rows->each(function ($row) use ($discountService, $hasIsActive, $hasBulkPrices) {
            $unavailable = !$row->product
                || $row->product->trashed()
                || ($hasIsActive && (int) ($row->product->Is_Active ?? 1) !== 1);

            $row->setAttribute('is_unavailable', $unavailable);

            if ($row->product) {
                $discountService->appendPriceAttributes($row->product);

                // Quantity-tier bulk pricing (TIER WINS over discounts).
                // Existing fields stay untouched for backward compat — the
                // storefront reads Effective_Unit_Price/Has_Bulk_Price and
                // falls back to Product_Final_Price when no tier matches.
                $tier = $hasBulkPrices
                    ? BulkPriceResolver::tierFor($row->product->bulkPrices, (int) $row->Quantity)
                    : null;

                if ($tier !== null) {
                    $row->setAttribute('Has_Bulk_Price', true);
                    $row->setAttribute('Bulk_Unit_Price', round($tier['unit_price'], 3));
                    $row->setAttribute('Bulk_Tier', [
                        'min_qty' => $tier['min_qty'],
                        'max_qty' => $tier['max_qty'],
                    ]);
                    $row->setAttribute('Effective_Unit_Price', round($tier['unit_price'], 3));
                } else {
                    $row->setAttribute('Has_Bulk_Price', false);
                    $row->setAttribute('Bulk_Unit_Price', null);
                    $row->setAttribute('Bulk_Tier', null);
                    $row->setAttribute(
                        'Effective_Unit_Price',
                        round((float) ($row->product->Product_Final_Price ?? $row->product->Product_Price ?? 0), 3)
                    );
                }
            }
        });

        return response()->json([
            'data' => $rows,
        ]);
    }

    // Sync guest cart from localStorage into DB, then return DB cart
    public function sync(Request $request)
    {
        $customer = $this->customerOrFail();

        $payload = $request->validate([
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($payload['items'] as $item) {
            $productId = (int) $item['product_id'];
            $qty       = (int) $item['quantity'];

            // Guest carts may reference products that have since been
            // deleted/deactivated — skip them instead of failing the merge.
            if (!$this->productAvailable($productId)) {
                continue;
            }

            $existing = CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->where('Products_Id', $productId)
                ->first();

            if ($existing) {
                // ✅ Your requirement: override DB qty with guest qty (latest)
                $existing->update(['Quantity' => $qty]);
            } else {
                CustomerCart::create([
                    'Customers_Id' => $customer->id,
                    'Products_Id'  => $productId,
                    'Quantity'     => $qty,
                ]);
            }
        }

        return $this->index();
    }


    public function setQuantity(Request $request)
    {
        $customer = $this->customerOrFail();

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity'   => ['required', 'integer', 'min:1'], // ✅ no zero here
        ]);

        $productId = (int) $data['product_id'];
        $qty       = (int) $data['quantity'];

        if (!$this->productAvailable($productId)) {
            return response()->json(['message' => 'This product is no longer available.'], 422);
        }

        $row = CustomerCart::query()->firstOrCreate(
            [
                'Customers_Id' => $customer->id,
                'Products_Id'  => $productId,
            ],
            [
                'Quantity'     => $qty,
            ]
        );

        if (!$row->wasRecentlyCreated) {
            $row->update(['Quantity' => $qty]);
        }

        return $this->index();
    }


    public function add(Request $request)
    {
        $customer = $this->customerOrFail();

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity'   => ['sometimes', 'integer', 'min:1'], // default 1
        ]);

        $productId = (int) $data['product_id'];
        $addQty    = (int) ($data['quantity'] ?? 1);

        if (!$this->productAvailable($productId)) {
            return response()->json(['message' => 'This product is no longer available.'], 422);
        }

        try{

       
        DB::transaction(function () use ($customer, $productId, $addQty) {
            $row = CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->where('Products_Id', $productId)
                ->lockForUpdate()
                ->first();

            if ($row) {
                $row->update(['Quantity' => ((int)$row->Quantity + $addQty)]);
            } else {
                CustomerCart::create([
                    'Customers_Id' => $customer->id,
                    'Products_Id'  => $productId,
                    'Quantity'     => $addQty,
                ]);
            }
        });

        return $this->index();

         } catch (\Exception $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function addOrIncrease(Request $request)
    {
        return $this->add($request);
    }

    public function merge(Request $request)
    {
        return $this->sync($request);
    }

    // Upsert a single item (logged-in add/update)
    // public function upsert(Request $request)
    // {
    //     $customer = $this->customerOrFail();

    //     $data = $request->validate([
    //         'product_id' => ['required', 'integer'],
    //         'quantity'   => ['required', 'integer', 'min:0'],
    //     ]);

    //     $productId = (int) $data['product_id'];
    //     $qty       = (int) $data['quantity'];

    //     $row = CustomerCart::query()
    //         ->where('Customers_Id', $customer->id)
    //         ->where('Products_Id', $productId)
    //         ->first();

    //     // quantity 0 => delete
    //     if ($qty === 0) {
    //         if ($row) $row->delete();
    //         return $this->index();
    //     }

    //     if ($row) {
    //         $row->update(['Quantity' => $qty]);
    //     } else {
    //         CustomerCart::create([
               
    //             'Customers_Id' => $customer->id,
    //             'Products_Id'  => $productId,
    //             'Quantity'     => $qty,
    //         ]);
    //     }

    //     return $this->index();
    // }

    public function remove(int $productId)
    {
        $customer = $this->customerOrFail();

        CustomerCart::query()
            ->where('Customers_Id', $customer->id)
            ->where('Products_Id', $productId)
            ->delete();

        return $this->index();
    }

    public function clear()
    {
        $customer = $this->customerOrFail();

        CustomerCart::query()
            ->where('Customers_Id', $customer->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
