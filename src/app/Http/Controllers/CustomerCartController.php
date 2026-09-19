<?php

namespace App\Http\Controllers;

use App\Models\CustomerCart;
use App\Models\Products;
use App\Services\VendorOffers;
use App\Services\Checkout\ActiveAmwalCheckoutGuard;
use App\Services\ProductDiscountService;
use App\Support\Pricing\BulkPriceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerCartController extends Controller
{
    private function customerOrFail()
    {
        $user = auth()->user();

        // You already have this helper
        return $user->customerOrCreate();
    }

    /**
     * Checkout and unpaid-order cancellation use this same customer row as
     * the per-customer serialization point. Every cart mutation must hold it
     * until the cart write commits so those flows cannot observe or overwrite
     * a half-mutated cart.
     */
    private function lockCustomerForCartMutation(int $customerId): void
    {
        $customer = DB::table('Customers_Master_T')
            ->where('id', $customerId)
            ->lockForUpdate()
            ->first(['id']);

        abort_unless($customer, 404, 'Customer not found.');
    }

    private function assertCartIsNotOwnedByPayment(int $customerId): void
    {
        $activeOrder = app(ActiveAmwalCheckoutGuard::class)->blockingOrder($customerId);

        if (! $activeOrder) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'The active card payment must be cancelled before the cart can be changed.',
            'code' => 'ACTIVE_AMWAL_PAYMENT',
            'active_order' => [
                'order_id' => (int) $activeOrder->id,
                'order_code' => $activeOrder->Order_Code ?? null,
                'payment_status' => $activeOrder->Payment_Status ?? null,
            ],
        ], 409));
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
            $unavailable = ! $row->product
                || $row->product->trashed()
                || ($hasIsActive && (int) ($row->product->Is_Active ?? 1) !== 1);

            $row->setAttribute('is_unavailable', $unavailable);

            if ($row->product && VendorOffers::ready()) {
                try {
                    $selected = VendorOffers::resolve($row->product, $row->Vendor_Offer_Id ? (int) $row->Vendor_Offer_Id : null);
                    $row->setRelation('product', $selected);
                    $row->setAttribute('Vendor_Offer_Id', $selected->Vendor_Offer_Id);
                } catch (\Illuminate\Validation\ValidationException) {
                    $row->setAttribute('is_unavailable', true);
                }
            }
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
            'items.*.vendor_offer_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($customer, $payload) {
            $this->lockCustomerForCartMutation((int) $customer->id);
            $this->assertCartIsNotOwnedByPayment((int) $customer->id);

            foreach ($payload['items'] as $item) {
                $productId = (int) $item['product_id'];
                $qty = (int) $item['quantity'];

                // Guest carts may reference products that have since been
                // deleted/deactivated — skip them instead of failing the merge.
                if (! $this->productAvailable($productId)) {
                    continue;
                }

                try {
                    $offerId = $this->offerId($productId, isset($item['vendor_offer_id']) ? (int) $item['vendor_offer_id'] : null);
                } catch (\Illuminate\Validation\ValidationException) {
                    // Retired seller offers are handled like retired guest-cart products.
                    continue;
                }
                $existing = CustomerCart::query()
                    ->where('Customers_Id', $customer->id)
                    ->where('Products_Id', $productId)
                    ->when(VendorOffers::ready(), fn ($q) => $q->where('Vendor_Offer_Id', $offerId))
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    // Override DB qty with guest qty (latest).
                    $existing->update(['Quantity' => $qty]);
                } else {
                    CustomerCart::create([
                        'Customers_Id' => $customer->id,
                        'Products_Id' => $productId,
                        ...(VendorOffers::ready() ? ['Vendor_Offer_Id' => $offerId] : []),
                        'Quantity' => $qty,
                    ]);
                }
            }
        }, 3);

        return $this->index();
    }

    public function setQuantity(Request $request)
    {
        $customer = $this->customerOrFail();

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'vendor_offer_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'], // ✅ no zero here
        ]);

        $productId = (int) $data['product_id'];
        $requestedOfferId = isset($data['vendor_offer_id']) ? (int) $data['vendor_offer_id'] : null;
        $qty = (int) $data['quantity'];

        $available = DB::transaction(function () use ($customer, $productId, $qty, $requestedOfferId) {
            $this->lockCustomerForCartMutation((int) $customer->id);
            $this->assertCartIsNotOwnedByPayment((int) $customer->id);

            if (! $this->productAvailable($productId)) {
                return false;
            }

            $offerId = $this->offerId($productId, $requestedOfferId);
            $row = CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->where('Products_Id', $productId)
                    ->when(VendorOffers::ready(), fn ($q) => $q->where('Vendor_Offer_Id', $offerId))
                ->lockForUpdate()
                ->first();

            if ($row) {
                $row->update(['Quantity' => $qty]);
            } else {
                CustomerCart::create([
                    'Customers_Id' => $customer->id,
                    'Products_Id' => $productId,
                        ...(VendorOffers::ready() ? ['Vendor_Offer_Id' => $offerId] : []),
                    'Quantity' => $qty,
                ]);
            }

            return true;
        }, 3);

        if (! $available) {
            return response()->json(['message' => 'This product is no longer available.'], 422);
        }

        return $this->index();
    }

    public function add(Request $request)
    {
        $customer = $this->customerOrFail();

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'vendor_offer_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['sometimes', 'integer', 'min:1'], // default 1
        ]);

        $productId = (int) $data['product_id'];
        $requestedOfferId = isset($data['vendor_offer_id']) ? (int) $data['vendor_offer_id'] : null;
        $addQty = (int) ($data['quantity'] ?? 1);

        $available = DB::transaction(function () use ($customer, $productId, $addQty, $requestedOfferId) {
            $this->lockCustomerForCartMutation((int) $customer->id);
            $this->assertCartIsNotOwnedByPayment((int) $customer->id);

            if (! $this->productAvailable($productId)) {
                return false;
            }

            $offerId = $this->offerId($productId, $requestedOfferId);
            $row = CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->where('Products_Id', $productId)
                    ->when(VendorOffers::ready(), fn ($q) => $q->where('Vendor_Offer_Id', $offerId))
                ->lockForUpdate()
                ->first();

            if ($row) {
                $row->update(['Quantity' => (int) $row->Quantity + $addQty]);
            } else {
                CustomerCart::create([
                    'Customers_Id' => $customer->id,
                    'Products_Id' => $productId,
                        ...(VendorOffers::ready() ? ['Vendor_Offer_Id' => $offerId] : []),
                    'Quantity' => $addQty,
                ]);
            }

            return true;
        }, 3);

        if (! $available) {
            return response()->json(['message' => 'This product is no longer available.'], 422);
        }

        return $this->index();
    }

    public function addOrIncrease(Request $request)
    {
        return $this->add($request);
    }

    public function merge(Request $request)
    {
        return $this->sync($request);
    }

    public function remove(Request $request, int $productId)
    {
        $customer = $this->customerOrFail();

        $data = $request->validate(['vendor_offer_id' => ['nullable', 'integer', 'min:1']]);
        $offerId = isset($data['vendor_offer_id']) ? (int) $data['vendor_offer_id'] : null;
        if (VendorOffers::ready() && $offerId === null) {
            $legacyVendorId = Products::withTrashed()->whereKey($productId)->value('Vendor_Id');
            if ($legacyVendorId) {
                $offerId = \App\Models\ProductVendorOffer::withTrashed()->where('Products_Id', $productId)->where('Vendor_Id', $legacyVendorId)->value('id');
            }
        }
        DB::transaction(function () use ($customer, $productId, $offerId) {
            $this->lockCustomerForCartMutation((int) $customer->id);
            $this->assertCartIsNotOwnedByPayment((int) $customer->id);

            CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->where('Products_Id', $productId)
                    ->when(VendorOffers::ready(), fn ($q) => $q->where('Vendor_Offer_Id', $offerId))
                ->delete();
        }, 3);

        return $this->index();
    }

    private function offerId(int $productId, ?int $offerId): ?int
    {
        $product = Products::query()->active()->findOrFail($productId);
        $selected = VendorOffers::resolve($product, $offerId);

        return $selected->Vendor_Offer_Id ? (int) $selected->Vendor_Offer_Id : null;
    }

    public function clear()
    {
        $customer = $this->customerOrFail();

        DB::transaction(function () use ($customer) {
            $this->lockCustomerForCartMutation((int) $customer->id);
            $this->assertCartIsNotOwnedByPayment((int) $customer->id);

            CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->delete();
        }, 3);

        return response()->json(['ok' => true]);
    }
}
