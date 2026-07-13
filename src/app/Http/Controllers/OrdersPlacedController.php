<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Products;
use App\Events\OrderPlaced;
use App\Models\OrdersPlacedVendors;
use App\Mail\NewOrderEmail;
use Illuminate\Support\Str;
use App\Events\OrderCreated;
use App\Models\OrdersPlaced;
use App\Models\CustomerCart;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\LoyalityPoints;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\OrdersPlacedDetails;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use App\Services\Checkout\PaymentStatus;
use App\Services\Checkout\PaymentGateway;
use App\Services\Checkout\StockDeduction;
use App\Services\Checkout\CheckoutIdempotency;
use App\Services\Checkout\ActiveAmwalCheckoutGuard;
use App\Services\Checkout\ShippingQuoteSelection;
use App\Services\Checkout\LoyaltyEarningPolicy;
use App\Services\Checkout\LoyaltyRedemptionCalculator;
use App\Services\ProductDiscountService;
use App\Services\Notifications\CustomerNotificationService;
use App\Support\Notifications\CustomerNotificationPayload;
use App\Support\Pricing\BulkPriceResolver;
use App\Support\Vendors\VendorCommissionCalculator;
use App\Models\ConxDatabaseNotification;
use App\Notifications\NewOrderNotification;
use InvalidArgumentException;

class OrdersPlacedController extends Controller
{


    public function reconcileCheckout(Request $request)
    {
        $customer = Auth::user()?->customers;
        $checkoutRequestKey = CheckoutIdempotency::requestKey(
            $request->header('Idempotency-Key'),
            $request->query('checkout_request_key'),
        );

        if (!$customer || !$checkoutRequestKey) {
            return response()->json(['message' => 'Checkout not found.'], 404);
        }

        // Serialize reconciliation with checkout creation. If the original
        // POST is still committing, this request waits on the same customer
        // lock and can only report 404 after that transaction has rolled back.
        $order = DB::transaction(function () use ($customer, $checkoutRequestKey) {
            $lockedCustomer = DB::table('Customers_Master_T')
                ->where('id', $customer->id)
                ->lockForUpdate()
                ->first(['id']);

            if (! $lockedCustomer) {
                return null;
            }

            return $this->findExistingCheckoutOrder(
                (int) $customer->id,
                $checkoutRequestKey,
                true,
            );
        }, 3);

        if (!$order || strtolower((string) ($order->Payment_Method ?? '')) !== 'card') {
            return response()->json(['message' => 'Checkout not found.'], 404);
        }

        return $this->idempotentOrderResponse($order);
    }


    public function index(Request $request)
    {
        $customer = Auth::user()?->customers;
        if (!$customer) return response()->json(['data' => [], 'pagination' => null]);

        $request->validate([
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date', 'after_or_equal:from'],
            'status'   => ['nullable', 'string', 'max:50'],
            'q'        => ['nullable', 'string', 'max:50'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = (int) ($request->input('per_page', 10));
        $perPage = max(1, min($perPage, 50));

        $query = OrdersPlaced::query()
            ->where('Customers_Id', $customer->id)
            // Card checkout rows are provisional until AmwalPay confirms a
            // capture. Failed/cancelled drafts remain as internal audit
            // tombstones for late callbacks but are not customer orders.
            ->where(function ($visible) {
                $visible->whereNull('Payment_Method')
                    ->orWhere('Payment_Method', '<>', 'card')
                    ->orWhereIn('Payment_Status', [
                        'paid',
                        'paid_requires_review',
                        'refunded',
                        'partially_refunded',
                    ]);
            })
            ->orderBy('id', 'desc');

        // date range
        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from')
                ? Carbon::parse($request->from)->startOfDay()
                : Carbon::parse('1970-01-01')->startOfDay();

            $to = $request->filled('to')
                ? Carbon::parse($request->to)->endOfDay()
                : Carbon::now()->endOfDay();

            $query->whereBetween('created_at', [$from, $to]);
        }

        // status
        if ($request->filled('status')) {
            $query->where('Status', $request->status);
        }

        // search
        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($sub) use ($q) {
                $sub->where('Transaction_Number', 'like', "%{$q}%")
                    ->orWhere('Order_Code', 'like', "%{$q}%");
            });
        }

        $p = $query->paginate($perPage);

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
        ]);
    }

    public function place(Request $request)
    {
        $validated = $request->validate([
            'delivery_method' => ['required', Rule::in(['ship', 'pickup'])],
            'location_id'     => [
                'nullable',
                'integer',
                Rule::requiredIf($request->delivery_method === 'pickup'),
                Rule::exists('Geox_Location_Master_T', 'id'),
            ],

            // keep them if you still send shipping info from UI
            'shipping_cost'   => 'required|numeric',

            'Customers_Contacts_Id' => [
                'nullable',
                'integer',
                'exists:Customers_Contact_T,id',
                Rule::requiredIf($request->input('delivery_method') === 'ship'),
            ],

            // shipping option (optional)
            'shipping_option' => [
                'nullable',
                'array',
                Rule::requiredIf($request->input('delivery_method') === 'ship'),
            ],
            'shipping_option.shipper_id'     => [
                'nullable',
                'integer',
                Rule::requiredIf($request->input('delivery_method') === 'ship'),
            ],
            'shipping_option.destination_id' => [
                'nullable',
                'integer',
                Rule::requiredIf($request->input('delivery_method') === 'ship'),
            ],
            'shipping_option.basis'          => 'nullable|in:weight,volume,heavy',
            'shipping_option.price'          => 'nullable|numeric',
            'shipping_option.currency'       => 'nullable|string|size:3',
            'shipping_option.weight_kg'      => 'nullable|numeric',
            'shipping_option.volume_cbm'     => 'nullable|numeric',
            'shipping_option.quoted_at'      => 'nullable|date',
            'shipping_option.expires_at'     => 'nullable|date',

            // payment
            'payment'         => 'nullable|array',
            'payment.method'  => 'nullable|in:card,cod,transfer,loyalty',
            'payment.currency' => ['nullable', Rule::in(['OMR'])],
            'payment.amount' => ['nullable', 'numeric', 'min:0'],
            // Cardholder data must be collected only inside Amwal SmartBox.
            'payment.card' => ['prohibited'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],

            // loyalty redemption
            'loyalty'             => 'nullable|array',
            'loyalty.use_points'  => 'nullable|boolean',
            'loyalty.points'      => 'nullable|integer|min:0',

            // totals from UI (OPTIONAL) - we will compute our own totals from DB cart
            'total'           => 'nullable|array',
            'total.subtotal'  => 'nullable|numeric',
            'total.vat'       => 'nullable|numeric',
            'total.grand'     => 'nullable|numeric',
        ]);

        $pay = $request->input('payment', []);
        $method = $pay['method'] ?? 'cod';
        $explicitIdempotencyKey = CheckoutIdempotency::requestKey(
            $request->header('Idempotency-Key'),
            $request->input('idempotency_key'),
        );
        $customer = null;
        $checkoutRequestKey = $explicitIdempotencyKey;

        DB::beginTransaction();

        try {
            $customer = Auth::user()?->customers;
            if (!$customer) {
                throw new \Exception("Customer not found for this user.");
            }

            // Serialize checkout creation per customer. Without this lock, two
            // different idempotency keys can both reserve stock before either
            // transaction sees the other's unpaid card order.
            $lockedCustomer = DB::table('Customers_Master_T')
                ->where('id', $customer->id)
                ->lockForUpdate()
                ->first(['id']);

            if (!$lockedCustomer) {
                throw new \Exception("Customer not found for this user.");
            }

            // Lock the authoritative server cart before deciding whether an
            // explicit idempotency key is a true replay or stale browser state.
            $cartRows = CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->lockForUpdate()
                ->get();

            if ($explicitIdempotencyKey) {
                $existing = $this->findExistingCheckoutOrder(
                    $customer->id,
                    $explicitIdempotencyKey,
                    true,
                );

                if ($existing) {
                    if ($cartRows->isNotEmpty()) {
                        DB::rollBack();

                        return response()->json([
                            'message' => 'This checkout key belongs to an earlier order and cannot be used with the current cart.',
                            'code' => 'STALE_CHECKOUT_KEY',
                            'active_order' => [
                                'order_id' => (int) $existing->id,
                                'order_code' => $existing->Order_Code ?? null,
                                'payment_status' => $existing->Payment_Status ?? null,
                                'amount' => isset($existing->Total_Price)
                                    ? number_format((float) $existing->Total_Price, 3, '.', '')
                                    : null,
                            ],
                        ], 409);
                    }

                    DB::rollBack();
                    return $this->idempotentOrderResponse($existing);
                }
            }

            if ($cartRows->isEmpty()) {
                throw new \Exception("Cart is empty.");
            }

            if ($method === 'card') {
                // A late signed success can arrive after the customer has
                // already cancelled the local draft and had the cart restored.
                // Until operations reconciles that captured transaction, do
                // not let the same customer start another card charge.
                $amwalGuard = app(ActiveAmwalCheckoutGuard::class);
                $reviewAmwalOrder = $amwalGuard->reconciliationOrder((int) $customer->id);

                if ($reviewAmwalOrder) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'A previous card payment was captured after cancellation and requires reconciliation before another card payment can be started.',
                        'code' => 'AMWAL_RECONCILIATION_REQUIRED',
                        'active_order' => [
                            'order_id' => (int) $reviewAmwalOrder->id,
                            'order_code' => $reviewAmwalOrder->Order_Code ?? null,
                            'payment_status' => 'paid_requires_review',
                            'amount' => isset($reviewAmwalOrder->Total_Price)
                                ? number_format((float) $reviewAmwalOrder->Total_Price, 3, '.', '')
                                : null,
                        ],
                    ], 409);
                }

                // Closing SmartBox restores the cart immediately, but the
                // gateway can still deliver a signed late capture for a short
                // period. Keep shopping available while refusing a new card
                // charge until that settlement uncertainty window has passed.
                $recentCancelledOrder = $amwalGuard->recentCancelledOrder((int) $customer->id);

                if ($recentCancelledOrder) {
                    $retryAfter = $amwalGuard->retryAfterSeconds($recentCancelledOrder);
                    DB::rollBack();

                    return response()->json([
                        'message' => "The previous card payment is still reconciling. Please retry in {$retryAfter} seconds.",
                        'code' => 'AMWAL_RETRY_COOLDOWN',
                        'retry_after_seconds' => $retryAfter,
                        'previous_order' => [
                            'order_id' => (int) $recentCancelledOrder->id,
                            'order_code' => $recentCancelledOrder->Order_Code ?? null,
                        ],
                    ], 409);
                }

                $activeAmwalOrder = DB::table('Orders_Placed_T')
                    ->where('Customers_Id', $customer->id)
                    ->where('Payment_Method', 'card')
                    ->whereIn('Status', ['pending', 'on-hold'])
                    ->where(function ($query) {
                        $query->whereNull('Payment_Status')
                            ->orWhereNotIn('Payment_Status', [
                                'paid',
                                'paid_requires_review',
                                'cancelled',
                                'refunded',
                                'partially_refunded',
                            ]);
                    })
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($activeAmwalOrder) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'An unpaid card order is already active. Cancel it before paying for a different cart.',
                        'code' => 'ACTIVE_AMWAL_ORDER_EXISTS',
                        'active_order' => [
                            'order_id' => (int) $activeAmwalOrder->id,
                            'order_code' => $activeAmwalOrder->Order_Code ?? null,
                            'payment_status' => $activeAmwalOrder->Payment_Status ?? null,
                            'amount' => isset($activeAmwalOrder->Total_Price)
                                ? number_format((float) $activeAmwalOrder->Total_Price, 3, '.', '')
                                : null,
                        ],
                    ], 409);
                }
            }

            // ✅ Load fulfillment data after replay/conflict checks.
            $selectedAddress = null;
            if ($validated['delivery_method'] === 'ship') {
                $selectedAddress = DB::table('Customers_Contact_T')
                    ->where('id', $validated['Customers_Contacts_Id'])
                    ->where('Customers_Contact_Id', $customer->id)
                    ->first();

                if (!$selectedAddress) {
                    throw new \Exception("Selected shipping address was not found for this customer.");
                }
            }

            $checkoutRequestKey = $explicitIdempotencyKey
                ?: CheckoutIdempotency::fingerprint($customer->id, $cartRows, $request->all());

            $existing = $this->findExistingCheckoutOrder($customer->id, $checkoutRequestKey, true);

            if ($existing) {
                DB::rollBack();

                return response()->json([
                    'message' => 'This checkout request already belongs to an earlier order and cannot be applied to a non-empty cart.',
                    'code' => 'STALE_CHECKOUT_KEY',
                    'active_order' => [
                        'order_id' => (int) $existing->id,
                        'order_code' => $existing->Order_Code ?? null,
                        'payment_status' => $existing->Payment_Status ?? null,
                        'amount' => isset($existing->Total_Price)
                            ? number_format((float) $existing->Total_Price, 3, '.', '')
                            : null,
                    ],
                ], 409);
            }

            // ✅ Preload all products
            // Bulk tiers table ships in an isc-admin-api migration — until it
            // exists this flag keeps checkout byte-for-byte on today's path.
            $hasBulkPrices = Schema::hasTable('Products_Bulk_Prices_T');

            $productIds = $cartRows->pluck('Products_Id')->unique()->values();
            $productsQuery = Products::query()
                ->whereIn('id', $productIds)
                ->lockForUpdate();

            if ($hasBulkPrices) {
                // Eager-load: exactly ONE extra query for the whole cart.
                $productsQuery->with('bulkPrices');
            }

            $productsMap = $productsQuery
                ->get()
                ->keyBy('id');

            // ✅ NEVER sell a deleted or deactivated product. Soft-deleted rows
            // are already excluded from $productsMap by the SoftDeletes trait;
            // deactivated ones are checked explicitly (guarded — the Is_Active
            // column may not exist yet on a lagging prod DB). This mirrors the
            // stock-validation failure (clear per-product message), but returns
            // 422 so the storefront can tell the customer to fix the cart.
            $hasIsActive = Schema::hasColumn('Products_Master_T', 'Is_Active');
            $unavailable = [];
            $unavailableProductIds = [];

            foreach ($cartRows as $cart) {
                $product = $productsMap->get($cart->Products_Id);

                if (!$product) {
                    $unavailable[] = "product ID {$cart->Products_Id}";
                    $unavailableProductIds[] = (int) $cart->Products_Id;
                } elseif ($hasIsActive && (int) ($product->Is_Active ?? 1) !== 1) {
                    $unavailable[] = "{$product->Product_Name} (ID {$product->id})";
                    $unavailableProductIds[] = (int) $cart->Products_Id;
                }
            }

            if (!empty($unavailable)) {
                DB::rollBack();

                // Self-heal: drop the dead cart rows (AFTER rollback so the
                // delete survives) — otherwise an invisible/unremovable line
                // would block this customer's checkout forever.
                CustomerCart::query()
                    ->where('Customers_Id', $customer->id)
                    ->whereIn('Products_Id', $unavailableProductIds)
                    ->delete();

                return response()->json([
                    'message' => 'Order failed',
                    'error'   => 'These items are no longer available and were removed from your cart: '
                        . implode(', ', $unavailable)
                        . '. Please review your cart and try again.',
                ], 422);
            }

            // ✅ Compute totals from DB (secure)
            $discountService = app(ProductDiscountService::class);
            $originalItemsSubtotal = 0.0;
            $productDiscountTotal = 0.0;
            $itemsSubtotal = 0.0;
            $itemsVat = 0.0;

            // VAT rate the admin set in Vat_Master_T (stored as a fraction, e.g. 0.05 = 5%).
            // Read the same source the storefront cart uses (GET /api/vat) so the charged VAT
            // matches exactly what the customer saw at cart/checkout.
            $vatRate = (float) (\App\Models\Vat::query()->value('Vat') ?? 0);

            // For vendor grouping
            $lines = [];          // per cart line
            $vendorTotals = [];   // vendor_id => ['subtotal'=>, 'vat'=>, 'lines'=>]
            $vendorSubTotalSum = 0.0;

            // Per-product commission columns are added by an admin-api migration; until
            // they exist this must behave exactly like the pre-commission flow.
            $hasProductCommission = Schema::hasColumn('Products_Master_T', 'Commission_Type');

            foreach ($cartRows as $cart) {
                $product = $productsMap->get($cart->Products_Id);
                if (!$product) {
                    throw new \Exception("Product not found: ID {$cart->Products_Id}");
                }

                $qty = (int) $cart->Quantity;

                // stock validation
                try {
                    StockDeduction::remainingAfterDeduction((int) $product->Product_Stock, $qty);
                } catch (InvalidArgumentException) {
                    throw new \Exception("Insufficient stock for {$product->Product_Name} (ID {$product->id})");
                }

                $pricing = $discountService->priceForProduct($product);
                $originalPrice = (float) $pricing['original_price'];
                $price = (float) $pricing['final_price'];
                $unitDiscount = (float) $pricing['discount_amount'];
                $lineDiscountInfo = $pricing['discount'];

                // ✅ TIER WINS: when a quantity-tier bulk price covers this
                // line's qty, the tier unit price replaces the (possibly
                // discounted) price and product discounts do NOT stack —
                // unit_discount = 0 and no Product_Discount_* snapshot.
                // original_price stays the base Product_Price. Nothing else
                // in the totals math changes: VAT/shipping/loyalty/vendor
                // split/commission all key off the line subtotal below.
                $bulkUnitPrice = $hasBulkPrices
                    ? BulkPriceResolver::unitPriceFor($product->bulkPrices, $qty)
                    : null;

                if ($bulkUnitPrice !== null) {
                    $price = round($bulkUnitPrice, 3);
                    $unitDiscount = 0.0;
                    $lineDiscountInfo = null;
                }

                $subtotal = $price * $qty;
                $originalLineSubtotal = $originalPrice * $qty;
                $lineDiscount = $unitDiscount * $qty;
                $vat = $subtotal * $vatRate;

                $originalItemsSubtotal += $originalLineSubtotal;
                $productDiscountTotal += $lineDiscount;
                $itemsSubtotal += $subtotal;
                $itemsVat += $vat;

                $vendorId = $product->Vendor_Id ?? null;
                if (empty($vendorId) || (int)$vendorId === 0) {
                    $vendorId = null;
                }

                if ($vendorId) {
                    if (!isset($vendorTotals[$vendorId])) {
                        $vendorTotals[$vendorId] = ['subtotal' => 0.0, 'vat' => 0.0, 'lines' => []];
                    }
                    $vendorTotals[$vendorId]['subtotal'] += $subtotal;
                    $vendorTotals[$vendorId]['vat'] += $vat;
                    $vendorSubTotalSum += $subtotal;

                    // Collect this vendor line for the per-product commission rollup.
                    // 'subtotal' is the SAME discounted pre-VAT value persisted to
                    // Orders_Placed_Details_T.Subtotal below; 'line_index' points at the
                    // $lines entry about to be appended so per-line snapshots can be
                    // written onto the matching detail row.
                    $vendorTotals[$vendorId]['lines'][] = [
                        'line_index'       => count($lines),
                        'subtotal'         => $subtotal,
                        'quantity'         => $qty,
                        'commission_type'  => $hasProductCommission ? ($product->Commission_Type ?? null) : null,
                        'commission_value' => $hasProductCommission ? ($product->Commission_Value ?? null) : null,
                    ];
                }

                $lines[] = [
                    'cart_id'    => $cart->id,
                    'product_id' => $product->id,
                    'vendor_id'  => $vendorId,
                    'qty'        => $qty,
                    'original_price' => $originalPrice,
                    'price'      => $price,
                    'unit_discount' => $unitDiscount,
                    'line_discount' => $lineDiscount,
                    'discount'   => $lineDiscountInfo,
                    'subtotal'   => $subtotal,
                    'vat'        => $vat,
                ];
            }

            // ✅ Fulfillment / shipping
            // Pickup orders should never keep a stale shipping option/cost from the browser.
            $shipping = $validated['delivery_method'] === 'ship'
                ? $request->input('shipping_option', [])
                : [];
            $shippingCost = $validated['delivery_method'] === 'ship'
                ? (float) ($shipping['price'] ?? ($validated['shipping_cost'] ?? 0))
                : 0.0;
            $shippingCurrency = $shipping['currency'] ?? 'OMR';
            $shippingQuote = null;

            // ✅ Final totals for main order
            if ($validated['delivery_method'] === 'ship') {
                $shipperId = (int) ($shipping['shipper_id'] ?? 0);
                $destinationId = (int) ($shipping['destination_id'] ?? 0);

                $activeShipperExists = DB::table('Shippers_Master_T')
                    ->where('id', $shipperId)
                    ->where('Shippers_Is_Active', 1)
                    ->exists();

                if (!$activeShipperExists) {
                    throw new \Exception("Selected shipper is not available.");
                }

                $selectedDestination = DB::table('Shipper_Destinations_T')
                    ->where('id', $destinationId)
                    ->where('Shippers_Id', $shipperId)
                    ->first();

                if (!$selectedDestination || !$this->destinationMatchesAddress($selectedDestination, $selectedAddress)) {
                    throw new \Exception("Selected shipper destination does not match the customer's shipping address.");
                }

                try {
                    ShippingQuoteSelection::assertNotExpired($shipping['expires_at'] ?? null);
                    $shippingQuote = $this->validatedShippingQuote($selectedAddress, $lines, $shipping);
                } catch (InvalidArgumentException $exception) {
                    throw new \Exception($exception->getMessage());
                }

                $shippingOption = $shippingQuote['option'];
                $shippingTotals = $shippingQuote['totals'];
                $shippingCost = (float) $shippingOption['total_price'];
                $shippingCurrency = $shippingOption['currency'] ?? $shippingCurrency;
                $shipping['basis'] = $shippingOption['basis'] ?? ($shipping['basis'] ?? null);
                $shipping['price'] = $shippingCost;
                $shipping['currency'] = $shippingCurrency;
                $shipping['weight_kg'] = $shippingTotals['weight_kg'] ?? ($shipping['weight_kg'] ?? null);
                $shipping['volume_cbm'] = $shippingTotals['volume_cbm'] ?? ($shipping['volume_cbm'] ?? null);
            }

            // VAT base = items subtotal + shipping (matches the storefront cart); shipping IS taxed.
            $totalVat = round(($itemsSubtotal + $shippingCost) * $vatRate, 3);

            $grandTotalBeforeLoyalty = $itemsSubtotal + $shippingCost + $totalVat;
            $loyaltyDiscountAmount = 0.0;
            $loyaltyPointsToRedeem = 0;
            $loyaltyRow = DB::table('Customers_Loyalty_T')
                ->where('Customer_Id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (!$loyaltyRow) {
                DB::table('Customers_Loyalty_T')->insert([
                    'Customers_Loyalty_Code' => CodeGenerator::createCode('LOYCODE', 'Customers_Loyalty_T', 'Customers_Loyalty_Code'),
                    'Customer_Id'            => $customer->id,
                    'Points_Earned'          => 0,
                    'Points_Redeemed'        => 0,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);

                $loyaltyRow = DB::table('Customers_Loyalty_T')
                    ->where('Customer_Id', $customer->id)
                    ->lockForUpdate()
                    ->first();
            }

            $loyaltyInput = $request->input('loyalty', []);
            $requestedRedeemPoints = !empty($loyaltyInput['use_points'])
                ? (int) ($loyaltyInput['points'] ?? 0)
                : 0;

            if ($requestedRedeemPoints > 0) {
                $availablePoints = max(0, (int) (($loyaltyRow->Points_Earned ?? 0) - ($loyaltyRow->Points_Redeemed ?? 0)));

                $loyaltySettings = LoyalityPoints::first();
                $redeemRulePoints = (float) ($loyaltySettings->Redeem_Points ?? 0);
                $redeemRuleAmount = (float) ($loyaltySettings->Redeem_Amount ?? 0);

                try {
                    $redemption = LoyaltyRedemptionCalculator::calculate(
                        requestedPoints: $requestedRedeemPoints,
                        availablePoints: $availablePoints,
                        orderTotal: $grandTotalBeforeLoyalty,
                        redeemRulePoints: $redeemRulePoints,
                        redeemRuleAmount: $redeemRuleAmount,
                    );
                } catch (InvalidArgumentException) {
                    throw new \Exception("Loyalty redemption is not configured.");
                }

                $loyaltyPointsToRedeem = $redemption['points'];
                $loyaltyDiscountAmount = (float) $redemption['amount'];
            }

            $grandTotal = round(max($grandTotalBeforeLoyalty - $loyaltyDiscountAmount, 0), 3);

            if ($grandTotal <= 0 && $loyaltyPointsToRedeem > 0) {
                $method = 'loyalty';
            }

            if ($method === 'loyalty' && $grandTotal > 0) {
                throw new \Exception("A payment method is required for the remaining order balance.");
            }

            $paymentStatus = PaymentStatus::initialFor($method, $grandTotal);
            $deferCardSettlement = $method === 'card' && $grandTotal > 0;
            $deferLoyaltySettlement = LoyaltyEarningPolicy::deferUntilPaymentConfirmed(
                $method,
                $grandTotal,
            );

            // ✅ Create Orders_Placed_T header
            $orderCode = CodeGenerator::createCode('ORD', 'Orders_Placed_T', 'order_code');
            $transactionNumber = strtoupper(Str::random(10));

            $orderPayload = [
                'Order_Code'              => $orderCode,
                'Customers_Contacts_Id'   => $validated['delivery_method'] === 'ship'
                    ? ($validated['Customers_Contacts_Id'] ?? null)
                    : null,
                'Transaction_Number'      => $transactionNumber,
                'Customers_Id'            => $customer->id,

                'Delivery_Type'           => $validated['delivery_method'],
                'Location_Id'             => $validated['delivery_method'] === 'pickup'
                    ? ($validated['location_id'] ?? null)
                    : null,

                'Total_Price'             => $grandTotal,
                'Status'                  => 'pending',
                'VAT'                     => $totalVat,
                'Sub_Total_Price'         => $itemsSubtotal,

                'Shippers_Id'             => $validated['delivery_method'] === 'ship' ? ($shipping['shipper_id'] ?? null) : null,
                'Shippers_Destination_Id' => $validated['delivery_method'] === 'ship' ? ($shipping['destination_id'] ?? null) : null,
                'Shipping_Basis'          => $validated['delivery_method'] === 'ship' ? ($shipping['basis'] ?? null) : null,
                'Shipping_Price'          => $shippingCost,
                'Shipping_Currency'       => $shippingCurrency,
                'Shipping_Weight_Kg'      => $validated['delivery_method'] === 'ship' ? ($shipping['weight_kg'] ?? null) : null,
                'Shipping_Volume_Cbm'     => $validated['delivery_method'] === 'ship' ? ($shipping['volume_cbm'] ?? null) : null,
                'Loyalty_Points_Redeemed' => $loyaltyPointsToRedeem,
                'Loyalty_Discount_Amount' => $loyaltyDiscountAmount,

                'created_at'              => now(),
                'updated_at'              => now(),
            ];

            if (Schema::hasColumn('Orders_Placed_T', 'Checkout_Request_Key')) {
                $orderPayload['Checkout_Request_Key'] = $checkoutRequestKey;
                $orderPayload['Checkout_Submitted_At'] = now();
            }

            if (Schema::hasColumn('Orders_Placed_T', 'Payment_Status')) {
                $orderPayload['Payment_Status'] = $paymentStatus;
                $orderPayload['Payment_Method'] = $method;
            }

            if (Schema::hasColumn('Orders_Placed_T', 'Shipping_Quote_Checked_At')) {
                $orderPayload['Shipping_Quote_Checked_At'] = $shippingQuote ? now() : null;
                $orderPayload['Shipping_Quote_Expires_At'] = $shipping['expires_at'] ?? null;
            }

            if (Schema::hasColumn('Orders_Placed_T', 'Original_Sub_Total_Price')) {
                $orderPayload['Original_Sub_Total_Price'] = round($originalItemsSubtotal, 3);
            }

            if (Schema::hasColumn('Orders_Placed_T', 'Product_Discount_Amount')) {
                $orderPayload['Product_Discount_Amount'] = round($productDiscountTotal, 3);
            }

            $orderId = DB::table('Orders_Placed_T')->insertGetId($orderPayload);

            // A provisional card checkout is not yet a customer order. Keep it
            // silent until the signed AmwalPay success path settles it.
            if (!$deferCardSettlement) {
                try {
                    ConxDatabaseNotification::create([
                        'type'            => 'App\\Notifications\\NewOrder',
                        'notifiable_type' => 'App\\Models\\Admin',
                        'notifiable_id'   => 1,
                        'data'            => [
                            'title'    => 'New Order Has Been Created',
                            'message'  => 'Order ' . $orderCode . ' has been created.',
                            'order_id' => $orderId,
                            'url'      => '/orders/' . $orderId,
                        ],
                    ]);
                } catch (\Throwable $notifyException) {
                    Log::error('Failed to create admin notification', [
                        'order_id'  => $orderId,
                        'error'     => $notifyException->getMessage(),
                        'exception' => $notifyException,
                    ]);
                }

                try {
                    app(CustomerNotificationService::class)->notifyUser(
                        (int) Auth::id(),
                        'customer.order_update',
                        CustomerNotificationPayload::orderUpdate(
                            orderId: (int) $orderId,
                            orderCode: $orderCode,
                            status: (string) ($orderPayload['Status'] ?? 'pending'),
                        ),
                    );
                } catch (\Throwable $notifyException) {
                    Log::error('Failed to create customer order notification', [
                        'order_id' => $orderId,
                        'customer_id' => $customer->id,
                        'error' => $notifyException->getMessage(),
                    ]);
                }
            }

            // ✅ Loyalty: redeem first, then earn on the payable amount
            $loyalitypoints = LoyalityPoints::first();
            if ($loyalitypoints) {
                $earnAmount = (float) ($loyalitypoints->Earn_Amount ?? 1);
                $earnPoints = (float) ($loyalitypoints->Earn_Points ?? $loyalitypoints->Point ?? 0);
                $pointsPerOmaniRial = $earnAmount > 0
                    ? $earnPoints / $earnAmount
                    : (float) ($loyalitypoints->Point ?? 0);
                $pointsEarned = (int) round($pointsPerOmaniRial * $grandTotal);

                $loyaltyUpdate = [
                    'Points_Redeemed' => DB::raw('Points_Redeemed + ' . $loyaltyPointsToRedeem),
                    'updated_at'      => now(),
                ];

                // Redeemed points are reserved immediately. New points are not
                // earned until card, COD, or transfer payment is confirmed.
                if (!$deferLoyaltySettlement) {
                    $loyaltyUpdate['Points_Earned'] = DB::raw('Points_Earned + ' . $pointsEarned);
                }

                DB::table('Customers_Loyalty_T')
                    ->where('Customer_Id', $customer->id)
                    ->update($loyaltyUpdate);

                if ($loyaltyPointsToRedeem > 0) {
                    DB::table('Customers_Loyalty_Transactions_T')->insert([
                        'Loyalty_Transaction_Code' => CodeGenerator::createCode('LOYTRANS', 'Customers_Loyalty_Transactions_T', 'Loyalty_Transaction_Code'),
                        'Customer_Id'              => $customer->id,
                        'Orders_Placed_Id'         => $orderId,
                        'Points_Earned'            => 0,
                        'Points_Redeemed'          => $loyaltyPointsToRedeem,
                        'Redeemed_Amount'          => $loyaltyDiscountAmount,
                        'created_at'               => now(),
                        'updated_at'               => now(),
                    ]);
                }

                if ($pointsEarned > 0 && !$deferLoyaltySettlement) {
                    DB::table('Customers_Loyalty_Transactions_T')->insert([
                        'Loyalty_Transaction_Code' => CodeGenerator::createCode('LOYTRANS', 'Customers_Loyalty_Transactions_T', 'Loyalty_Transaction_Code'),
                        'Customer_Id'              => $customer->id,
                        'Orders_Placed_Id'         => $orderId,
                        'Points_Earned'            => $pointsEarned,
                        'Points_Redeemed'          => 0,
                        'Redeemed_Amount'          => 0,
                        'created_at'               => now(),
                        'updated_at'               => now(),
                    ]);
                }
            }

            // ✅ Sales transaction header
            $transactionHeaderCode = CodeGenerator::createCode('TRANS', 'Sales_Transaction_Header_T', 'Sales_Transaction_Header_code');

            $sthId = DB::table('Sales_Transaction_Header_T')->insertGetId([
                'Sales_Transaction_Header_code' => $transactionHeaderCode,
                'Bill_No'                       => $transactionNumber,
                'created_at'                    => now(),
                'updated_at'                    => now(),
                'Orders_Placed_Id'              => $orderId,
            ]);

            // ✅ Sales transactions details (keep your logic)
            $Merchant_Id = strtoupper(Str::random(10));
            $billNumber = $transactionNumber;
            $paymentIntent = app(PaymentGateway::class)->createIntent(
                method: $method,
                amount: $grandTotal,
                currency: 'OMR',
                paymentPayload: $pay,
                context: [
                    'order_id' => $orderId,
                    'order_code' => $orderCode,
                    'idempotency_key' => $checkoutRequestKey,
                ],
            );

            $detailsCode = CodeGenerator::createCode('ORDP', 'Sales_Transactions_Details_T', 'Sales_Transactions_Details_code');

            $base = [
                'Sales_Transactions_Details_code' => $detailsCode,
                'Sales_Transaction_Header_Id'     => $sthId,
                'Transaction_No'                  => $transactionNumber,
                'Merchant_Id'                     => $Merchant_Id,
                'Bill_No'                         => $billNumber,
                'Discount_Amount'                 => $loyaltyDiscountAmount,
                'VAT_Tax_Amount'                  => $totalVat,
                'Transaction_Date'                => now(),
                'created_at'                      => now(),
                'updated_at'                      => now(),
            ];

            $payCols = [
                'Payment_Method'   => $paymentIntent->method,
                'Payment_Status'   => $paymentIntent->status,
                'Payment_Amount'   => $paymentIntent->amount,
                'Payment_Currency' => $paymentIntent->currency,
            ];

            if (Schema::hasColumn('Sales_Transactions_Details_T', 'Payment_Gateway')) {
                $payCols['Payment_Gateway'] = $paymentIntent->gateway;
                $payCols['Payment_Intent_Id'] = $paymentIntent->intentId;
                $payCols['Payment_Idempotency_Key'] = $checkoutRequestKey;
                $payCols['Payment_Metadata'] = json_encode($paymentIntent->metadata);
            }

            $specific = [];
            if ($method === 'card') {
                // SmartBox owns all PAN/CVC/cardholder collection. Card metadata is
                // populated only from a verified gateway response when available.
                $specific = [
                    'Card_Brand'     => null,
                    'Card_Last4'     => null,
                    'Card_Exp_Month' => null,
                    'Card_Exp_Year'  => null,
                ];
            } elseif ($method === 'transfer') {
                $tr = $pay['transfer'] ?? [];
                $specific = [
                    'Transfer_Reference'  => $tr['reference'] ?? null,
                    'Transfer_Payer_Name' => $tr['payer_name'] ?? null,
                ];
            } elseif ($method === 'loyalty') {
                $specific = [
                    'COD_Collected'    => null,
                    'COD_Collected_At' => null,
                    'COD_Note'         => null,
                ];
            } else {
                $specific = [
                    'COD_Collected'    => null,
                    'COD_Collected_At' => null,
                    'COD_Note'         => null,
                ];
            }

            DB::table('Sales_Transactions_Details_T')->insert(array_merge($base, $payCols, $specific));

            // ✅ Create vendor headers (ONLY for vendor products)
            $vendorOrderIds = []; // vendor_id => vendor_order_id
            $hasVendorCommissionSource = Schema::hasColumn('Orders_Placed_Vendors_T', 'Commission_Source');
            $hasDetailCommission = Schema::hasColumn('Orders_Placed_Details_T', 'Commission_Amount');

            foreach ($vendorTotals as $vendorId => $totals) {

                // shipping allocation across vendor order headers by subtotal proportion.
                // Detail lines remain linked by Orders_Placed_Vendor_Id for product-level commission later.
                $vendorShipping = 0.0;
                if ($vendorSubTotalSum > 0) {
                    $vendorShipping = $shippingCost * ($totals['subtotal'] / $vendorSubTotalSum);
                }
                $vendorShipping = round($vendorShipping, 3);
                // Tax this vendor's allocated shipping too, so the vendor VAT reconciles with the order total.
                $vendorVat = round($totals['vat'] + ($vendorShipping * $vatRate), 3);

                $vendorSubTotal = round($totals['subtotal'], 3);

                // Per-product commission rollup: auto-compute ONLY when every line in
                // this vendor's group has a valid product commission AND the sum does
                // not exceed the vendor subtotal. Otherwise fall back to the manual
                // flow exactly as before (nulls + 'pending').
                $commissionPlan = VendorCommissionCalculator::planForVendorLines($totals['lines'] ?? []);
                $autoCommission = $commissionPlan['covered'] && $commissionPlan['total'] <= $vendorSubTotal;

                $vendorOrderPayload = [
                    'Orders_Placed_Id'   => $orderId,
                    'Vendor_Id'          => $vendorId,
                    'Vendor_Order_Code'  => CodeGenerator::createCode('VORD', 'Orders_Placed_Vendors_T', 'Vendor_Order_Code'),

                    'Sub_Total'          => $vendorSubTotal,
                    'VAT'                => $vendorVat,
                    'Shipping'           => $vendorShipping,
                    'Total'              => round($totals['subtotal'] + $vendorVat + $vendorShipping, 3),

                    'Status'             => $autoCommission ? 'commission_auto' : 'pending',
                    'Commission_Type'    => $autoCommission ? 'auto' : null,
                    'Commission_Value'   => null,
                    'Commission_Amount'  => $autoCommission ? $commissionPlan['total'] : null,
                    'Payout_Status'      => 'unpaid',
                ];

                if ($hasVendorCommissionSource) {
                    $vendorOrderPayload['Commission_Source'] = $autoCommission ? 'auto' : null;
                }

                if ($autoCommission) {
                    // Stash per-line snapshots on the matching $lines entries so the
                    // detail insert below persists them onto Orders_Placed_Details_T.
                    foreach ($totals['lines'] as $vendorLineIdx => $vendorLine) {
                        $lineIndex = $vendorLine['line_index'];
                        $lines[$lineIndex]['commission_type'] = $vendorLine['commission_type'];
                        $lines[$lineIndex]['commission_value'] = $vendorLine['commission_value'];
                        $lines[$lineIndex]['commission_amount'] = $commissionPlan['lines'][$vendorLineIdx];
                    }
                }

                if (Schema::hasColumn('Orders_Placed_Vendors_T', 'Net_Sub_Total')) {
                    $vendorOrderPayload['Returned_Quantity'] = 0;
                    $vendorOrderPayload['Refunded_Amount'] = 0;
                    $vendorOrderPayload['Net_Sub_Total'] = round($totals['subtotal'], 3);
                    $vendorOrderPayload['Adjusted_Commission_Amount'] = null;
                    // When the commission is auto-computed at checkout, the pending
                    // payout is already known: subtotal minus commission. Leaving it
                    // at the full subtotal made reports show payout + commission >
                    // net sales until a refund/payout overwrote it.
                    $vendorOrderPayload['Net_Payout_Amount'] = $autoCommission
                        ? round(max($vendorSubTotal - (float) $commissionPlan['total'], 0), 3)
                        : round($totals['subtotal'], 3);
                    $vendorOrderPayload['Payout_Adjustment_Amount'] = 0;
                }

                $vendorOrder = OrdersPlacedVendors::create($vendorOrderPayload);

                $vendorOrderIds[$vendorId] = $vendorOrder->id;

                // Card orders become vendor sales only after AmwalPay confirms payment.
                if (!$deferCardSettlement) {
                    try {
                        ConxDatabaseNotification::create([
                            'type'            => 'App\\Notifications\\VendorNewSale',
                            'notifiable_type' => 'App\\Models\\Vendor',
                            'notifiable_id'   => $vendorId,
                            'data'            => [
                                'title'   => 'New sale',
                                'message' => 'You have a new order (' . $vendorOrder->Vendor_Order_Code . ') totalling '
                                    . number_format((float) $vendorOrder->Total, 3) . '.',
                                'url'     => '/orders',
                            ],
                        ]);
                    } catch (\Throwable $vendorNotifyException) {
                        Log::error('Failed to create vendor sale notification', [
                            'vendor_id' => $vendorId,
                            'order_id'  => $orderId,
                            'error'     => $vendorNotifyException->getMessage(),
                        ]);
                    }
                }
            }

            // ✅ Insert order details & update stock
            foreach ($lines as $ln) {
                $orderplacecode = CodeGenerator::createCode('ORDP', 'Orders_Placed_Details_T', 'Order_Placed_Code');

                $vendorId = $ln['vendor_id'];
                $vendorOrderId = $vendorId ? ($vendorOrderIds[$vendorId] ?? null) : null;

                $detailPayload = [
                    'Order_Placed_Code'       => $orderplacecode,
                    'Orders_Placed_Id'        => $orderId,
                    'Cart_Id'                 => $ln['cart_id'],

                    'Products_Id'             => $ln['product_id'],
                    'Quantity'                => $ln['qty'],
                    'Price'                   => $ln['price'],
                    'Subtotal'                => $ln['subtotal'],
                    'Vat'                     => $ln['vat'],

                    'Vendor_Id'               => $vendorId,
                    'Orders_Placed_Vendor_Id' => $vendorOrderId,

                    'Status'                  => 'pending',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ];

                if (Schema::hasColumn('Orders_Placed_Details_T', 'Original_Unit_Price')) {
                    $detailPayload['Original_Unit_Price'] = round($ln['original_price'], 3);
                    $detailPayload['Discounted_Unit_Price'] = round($ln['price'], 3);
                    $detailPayload['Product_Discount_Id'] = $ln['discount']['id'] ?? null;
                    $detailPayload['Product_Discount_Code'] = $ln['discount']['code'] ?? null;
                    $detailPayload['Product_Discount_Name'] = $ln['discount']['name'] ?? null;
                    $detailPayload['Product_Discount_Type'] = $ln['discount']['type'] ?? null;
                    $detailPayload['Product_Discount_Value'] = $ln['discount']['value'] ?? null;
                    $detailPayload['Product_Discount_Amount'] = round($ln['unit_discount'], 3);
                    $detailPayload['Line_Discount_Amount'] = round($ln['line_discount'], 3);
                }

                if (Schema::hasColumn('Orders_Placed_Details_T', 'Sold_Amount')) {
                    $detailPayload['Sold_Amount'] = round($ln['subtotal'], 3);
                    $detailPayload['Returned_Quantity'] = 0;
                    $detailPayload['Refunded_Amount'] = 0;
                    $detailPayload['Net_Amount'] = round($ln['subtotal'], 3);
                    $detailPayload['Return_State'] = 'not_returned';
                    $detailPayload['Refund_State'] = 'not_refunded';
                }

                // Per-line commission snapshot: only set when this line's vendor order
                // was auto-computed above (commission_auto). Uncovered vendor groups and
                // in-house lines keep these columns NULL.
                if ($hasDetailCommission && array_key_exists('commission_amount', $ln)) {
                    $detailPayload['Commission_Type'] = $ln['commission_type'];
                    $detailPayload['Commission_Value'] = $ln['commission_value'];
                    $detailPayload['Commission_Amount'] = $ln['commission_amount'];
                }

                DB::table('Orders_Placed_Details_T')->insert($detailPayload);

                $affectedRows = Products::where('id', $ln['product_id'])
                    ->where('Product_Stock', '>=', $ln['qty'])
                    ->update([
                        'Product_Stock' => DB::raw("Product_Stock - {$ln['qty']}"),
                        'Status' => DB::raw("CASE WHEN Product_Stock - {$ln['qty']} <= 0 THEN 'out_of_stock' ELSE Status END"),
                    ]);

                if ($affectedRows !== 1) {
                    throw new \Exception("Insufficient stock while placing order for product ID {$ln['product_id']}");
                }
            }

            // ✅ Clear cart
            CustomerCart::where('Customers_Id', $customer->id)->delete();

            DB::commit();

            if (!$deferCardSettlement) {
                try {
                    event(new OrderPlaced($orderId, $orderCode, (float) $grandTotal));

                    event(new OrderCreated($orderId, [
                        'title'    => 'New Order Placed',
                        'message'  => 'Order ' . $orderCode . ' has been placed.',
                        'order_id' => $orderId,
                    ]));

                    $to = 'buzz644@yahoo.com';
                    Mail::to($to)->queue(new NewOrderEmail($orderCode));
                } catch (\Throwable $e) {
                    Log::error('Pusher/Mail failed', [
                        'order_id' => $orderId,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message'    => 'Order placed successfully',
                'order_code' => $orderCode,
                'order_id'   => $orderId,
                'payment'    => [
                    'method' => $paymentIntent->method,
                    'status' => $paymentIntent->status,
                    'gateway' => $paymentIntent->gateway,
                    'requires_action' => $paymentIntent->method === 'card'
                        && $paymentIntent->status !== PaymentStatus::PAID,
                    'configuration_url' => $paymentIntent->method === 'card'
                        ? "/api/payments/amwal/orders/{$orderId}/configuration"
                        : null,
                    'status_url' => $paymentIntent->method === 'card'
                        ? "/api/payments/amwal/orders/{$orderId}/status"
                        : null,
                ],
                'loyalty'    => [
                    'points_redeemed' => $loyaltyPointsToRedeem,
                    'discount_amount' => $loyaltyDiscountAmount,
                ],
                'totals'     => [
                    'subtotal' => round($itemsSubtotal, 3),
                    'original_subtotal' => round($originalItemsSubtotal, 3),
                    'product_discount' => round($productDiscountTotal, 3),
                    'vat' => round($totalVat, 3),
                    'shipping' => round($shippingCost, 3),
                    'before_loyalty' => round($grandTotalBeforeLoyalty, 3),
                    'grand' => round($grandTotal, 3),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            if ($customer && $checkoutRequestKey && $this->isDuplicateCheckoutKeyException($e)) {
                $existing = $this->findExistingCheckoutOrder((int) $customer->id, $checkoutRequestKey);

                if ($existing) {
                    $hasCurrentCart = CustomerCart::query()
                        ->where('Customers_Id', $customer->id)
                        ->exists();

                    if ($hasCurrentCart) {
                        return response()->json([
                            'message' => 'This checkout key belongs to an earlier order and cannot be used with the current cart.',
                            'code' => 'STALE_CHECKOUT_KEY',
                            'active_order' => [
                                'order_id' => (int) $existing->id,
                                'order_code' => $existing->Order_Code ?? null,
                                'payment_status' => $existing->Payment_Status ?? null,
                                'amount' => isset($existing->Total_Price)
                                    ? number_format((float) $existing->Total_Price, 3, '.', '')
                                    : null,
                            ],
                        ], 409);
                    }

                    return $this->idempotentOrderResponse($existing);
                }
            }

            return response()->json([
                'message' => 'Order failed',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    private function findExistingCheckoutOrder(
        int $customerId,
        ?string $checkoutRequestKey,
        bool $lockForUpdate = false,
    ): ?object
    {
        if (!$checkoutRequestKey || !Schema::hasColumn('Orders_Placed_T', 'Checkout_Request_Key')) {
            return null;
        }

        $query = DB::table('Orders_Placed_T')
            ->where('Customers_Id', $customerId)
            ->where('Checkout_Request_Key', $checkoutRequestKey)
            ->orderByDesc('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function idempotentOrderResponse(object $order)
    {
        $paymentMethod = $order->Payment_Method ?? null;
        $paymentStatus = $order->Payment_Status ?? null;
        $payable = $paymentMethod === 'card'
            && strtolower((string) ($order->Status ?? '')) === 'pending'
            && !in_array($paymentStatus, [PaymentStatus::PAID, 'paid_requires_review', 'cancelled'], true);

        return response()->json([
            'message' => 'Order already placed for this checkout request.',
            'idempotent' => true,
            'order_code' => $order->Order_Code ?? null,
            'order_id' => $order->id,
            'payment' => [
                'method' => $paymentMethod,
                'status' => $paymentStatus,
                'gateway' => $paymentMethod === 'card' ? 'amwal_smartbox' : 'pending_gateway',
                'paid' => $paymentStatus === PaymentStatus::PAID,
                'payable' => $payable,
                'requires_action' => $payable,
                'configuration_url' => $paymentMethod === 'card'
                    ? "/api/payments/amwal/orders/{$order->id}/configuration"
                    : null,
                'status_url' => $paymentMethod === 'card'
                    ? "/api/payments/amwal/orders/{$order->id}/status"
                    : null,
            ],
            'totals' => [
                'grand' => isset($order->Total_Price) ? round((float) $order->Total_Price, 3) : null,
            ],
        ], 200);
    }

    private function isDuplicateCheckoutKeyException(\Exception $exception): bool
    {
        if (!$exception instanceof QueryException) {
            return false;
        }

        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['2601', '2627', '1062'], true)
            || str_contains($message, 'ux_orders_checkout_request_key');
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $shipping
     *
     * @return array{option: array<string, mixed>, totals: array<string, mixed>}
     */
    private function validatedShippingQuote(object $selectedAddress, array $lines, array $shipping): array
    {
        $quoteItems = array_map(fn (array $line) => [
            'product_id' => $line['product_id'],
            'qty' => $line['qty'],
        ], $lines);

        $quoteRequest = Request::create('/api/v1/shipping/quotes', 'POST', [
            'address_id' => $selectedAddress->id,
            'items' => $quoteItems,
        ]);

        $quoteResponse = app(ShippingQuoteController::class)->quote($quoteRequest);
        $quoteData = $quoteResponse->getData(true);

        $option = ShippingQuoteSelection::match($quoteData['options'] ?? [], [
            'shipper_id' => $shipping['shipper_id'] ?? null,
            'destination_id' => $shipping['destination_id'] ?? null,
            'basis' => $shipping['basis'] ?? null,
            'price' => $shipping['price'] ?? null,
        ]);

        return [
            'option' => $option,
            'totals' => $quoteData['totals'] ?? [],
        ];
    }


    public function getOrderDetails($id)
    {
        $customer = Auth::user()?->customers;
        if (!$customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $order = DB::table('Orders_Placed_T')
            ->where('id', $id)
            ->where('Customers_Id', $customer->id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (strtolower((string) ($order->Payment_Method ?? '')) === 'card'
            && ! in_array(strtolower((string) ($order->Payment_Status ?? '')), [
                'paid',
                'paid_requires_review',
                'refunded',
                'partially_refunded',
            ], true)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $imagePick = DB::table('Products_Images_T')
            ->select('Products_Id', DB::raw('MIN(id) as image_id'))
            ->groupBy('Products_Id');

        $itemSelect = [
                'd.id',
                'd.Order_Placed_Code',
                'd.Products_Id',
                'd.Quantity',
                'd.Price',
                'd.Subtotal',
                'd.Vat',
                'd.Status',
                'p.Product_Name',
                'p.Product_Code',
                'p.Product_Sku',
                'p.Slug',
                'pi.Image_Path',
            ];

        if (Schema::hasColumn('Orders_Placed_Details_T', 'Original_Unit_Price')) {
            $itemSelect = array_merge($itemSelect, [
                'd.Original_Unit_Price',
                'd.Discounted_Unit_Price',
                'd.Product_Discount_Id',
                'd.Product_Discount_Code',
                'd.Product_Discount_Name',
                'd.Product_Discount_Type',
                'd.Product_Discount_Value',
                'd.Product_Discount_Amount',
                'd.Line_Discount_Amount',
            ]);
        }

        $items = DB::table('Orders_Placed_Details_T as d')
            ->leftJoin('Products_Master_T as p', 'p.id', '=', 'd.Products_Id')
            ->leftJoinSub($imagePick, 'pi_pick', function ($join) {
                $join->on('pi_pick.Products_Id', '=', 'p.id');
            })
            ->leftJoin('Products_Images_T as pi', 'pi.id', '=', 'pi_pick.image_id')
            ->where('d.Orders_Placed_Id', $order->id)
            ->select($itemSelect)
            ->orderBy('d.id')
            ->get()
            ->map(function ($item) {
                $unitDiscount = (float) ($item->Product_Discount_Amount ?? 0);
                $lineDiscount = (float) ($item->Line_Discount_Amount ?? 0);
                $originalUnit = (float) ($item->Original_Unit_Price ?? $item->Price ?? 0);

                return [
                    'id' => (int) $item->id,
                    'order_line_code' => $item->Order_Placed_Code,
                    'product_id' => $item->Products_Id ? (int) $item->Products_Id : null,
                    'product_name' => $item->Product_Name,
                    'product_code' => $item->Product_Sku ?: $item->Product_Code,
                    'product_slug' => $item->Slug,
                    'image_path' => $item->Image_Path,
                    'quantity' => (int) ($item->Quantity ?? 0),
                    'unit_price' => (float) ($item->Price ?? 0),
                    'original_unit_price' => $originalUnit,
                    'discounted_unit_price' => (float) ($item->Discounted_Unit_Price ?? $item->Price ?? 0),
                    'unit_discount_amount' => $unitDiscount,
                    'line_discount_amount' => $lineDiscount,
                    'discount' => !empty($item->Product_Discount_Id) ? [
                        'id' => (int) $item->Product_Discount_Id,
                        'code' => $item->Product_Discount_Code ?? null,
                        'name' => $item->Product_Discount_Name ?? null,
                        'type' => $item->Product_Discount_Type ?? null,
                        'value' => isset($item->Product_Discount_Value) ? (float) $item->Product_Discount_Value : null,
                    ] : null,
                    'subtotal' => (float) ($item->Subtotal ?? (($item->Price ?? 0) * ($item->Quantity ?? 0))),
                    'vat' => (float) ($item->Vat ?? 0),
                    'status' => $item->Status,
                ];
            })
            ->values();

        $transactionHeader = $this->findTransactionHeader($order);
        $paymentRow = $transactionHeader
            ? DB::table('Sales_Transactions_Details_T')
                ->where('Sales_Transaction_Header_Id', $transactionHeader->id)
                ->orderByDesc('id')
                ->first()
            : null;

        $address = null;
        if (!empty($order->Customers_Contacts_Id)) {
            $address = DB::table('Customers_Contact_T')
                ->where('id', $order->Customers_Contacts_Id)
                ->first();
        }

        $location = null;
        if (!empty($order->Location_Id)) {
            $location = DB::table('Geox_Location_Master_T')
                ->where('id', $order->Location_Id)
                ->first();
        }

        $shipper = null;
        if (!empty($order->Shippers_Id)) {
            $shipper = DB::table('Shippers_Master_T')
                ->where('id', $order->Shippers_Id)
                ->first();
        }

        $destination = null;
        if (!empty($order->Shippers_Destination_Id)) {
            $destination = DB::table('Shipper_Destinations_T')
                ->where('id', $order->Shippers_Destination_Id)
                ->first();
        }

        $subtotal = (float) ($order->Sub_Total_Price ?? $items->sum('subtotal'));
        $productDiscount = (float) ($order->Product_Discount_Amount ?? $items->sum('line_discount_amount'));
        $originalSubtotal = (float) ($order->Original_Sub_Total_Price ?? ($subtotal + $productDiscount));
        $vat = (float) ($order->VAT ?? $items->sum('vat'));
        $shipping = (float) ($order->Shipping_Price ?? 0);
        $loyaltyDiscount = (float) ($order->Loyalty_Discount_Amount ?? 0);
        $totalBeforeLoyalty = round($subtotal + $vat + $shipping, 3);
        $grandTotal = (float) ($order->Total_Price ?? max($totalBeforeLoyalty - $loyaltyDiscount, 0));

        return response()->json([
            'order' => [
                'id' => (int) $order->id,
                'order_code' => $order->Order_Code ?? null,
                'transaction_number' => $order->Transaction_Number ?? null,
                'status' => $order->Status ?? null,
                'created_at' => $order->created_at ?? null,
                'updated_at' => $order->updated_at ?? null,
            ],
            'items' => $items,
            'fulfillment' => [
                'type' => $order->Delivery_Type ?: (!empty($order->Customers_Contacts_Id) ? 'ship' : 'pickup'),
                'shipping' => [
                    'shipper_id' => $order->Shippers_Id ? (int) $order->Shippers_Id : null,
                    'shipper_name' => $shipper->Shippers_Name ?? $shipper->Shipper_Name ?? null,
                    'destination_id' => $order->Shippers_Destination_Id ? (int) $order->Shippers_Destination_Id : null,
                    'destination_label' => $this->formatDestination($destination),
                    'basis' => $order->Shipping_Basis ?? null,
                    'price' => $shipping,
                    'currency' => $order->Shipping_Currency ?? 'OMR',
                    'weight_kg' => isset($order->Shipping_Weight_Kg) ? (float) $order->Shipping_Weight_Kg : null,
                    'volume_cbm' => isset($order->Shipping_Volume_Cbm) ? (float) $order->Shipping_Volume_Cbm : null,
                    'address' => $this->formatAddress($address),
                ],
                'pickup' => [
                    'location_id' => $order->Location_Id ? (int) $order->Location_Id : null,
                    'location_name' => $location->Location_Name ?? null,
                    'location_code' => $location->Location_Code ?? null,
                ],
            ],
            'payment' => $this->formatPayment($paymentRow),
            'transaction' => [
                'header_code' => $transactionHeader->Sales_Transaction_Header_code ?? null,
                'bill_no' => $transactionHeader->Bill_No ?? null,
            ],
            'totals' => [
                'original_subtotal' => round($originalSubtotal, 3),
                'product_discount' => round($productDiscount, 3),
                'subtotal' => round($subtotal, 3),
                'vat' => round($vat, 3),
                'shipping' => round($shipping, 3),
                'before_loyalty' => round($totalBeforeLoyalty, 3),
                'loyalty_points_redeemed' => (int) ($order->Loyalty_Points_Redeemed ?? 0),
                'loyalty_discount' => round($loyaltyDiscount, 3),
                'grand_total' => round($grandTotal, 3),
                'currency' => $paymentRow->Payment_Currency ?? $order->Shipping_Currency ?? 'OMR',
            ],
        ]);
    }

    private function findTransactionHeader(object $order): ?object
    {
        $hasOrdersPlacedColumn = Schema::hasColumn('Sales_Transaction_Header_T', 'Orders_Placed_Id');
        $hasOrderPlacedColumn = Schema::hasColumn('Sales_Transaction_Header_T', 'Order_Placed_Id');
        $hasTransactionNumber = !empty($order->Transaction_Number);

        if (!$hasOrdersPlacedColumn && !$hasOrderPlacedColumn && !$hasTransactionNumber) {
            return null;
        }

        $query = DB::table('Sales_Transaction_Header_T');
        $query->where(function ($q) use ($order, $hasOrdersPlacedColumn, $hasOrderPlacedColumn, $hasTransactionNumber) {
            $hasCondition = false;

            if ($hasOrdersPlacedColumn) {
                $q->where('Orders_Placed_Id', $order->id);
                $hasCondition = true;
            }

            if ($hasOrderPlacedColumn) {
                $hasCondition
                    ? $q->orWhere('Order_Placed_Id', $order->id)
                    : $q->where('Order_Placed_Id', $order->id);
                $hasCondition = true;
            }

            if ($hasTransactionNumber) {
                $hasCondition
                    ? $q->orWhere('Bill_No', $order->Transaction_Number)
                    : $q->where('Bill_No', $order->Transaction_Number);
            }
        });

        return $query->orderByDesc('id')->first();
    }

    private function formatPayment(?object $payment): array
    {
        if (!$payment) {
            return [
                'method' => null,
                'status' => null,
                'amount' => null,
                'currency' => 'OMR',
                'card' => null,
                'transfer' => null,
                'cod' => null,
            ];
        }

        return [
            'method' => $payment->Payment_Method ?? null,
            'status' => $payment->Payment_Status ?? null,
            'amount' => isset($payment->Payment_Amount) ? (float) $payment->Payment_Amount : null,
            'currency' => $payment->Payment_Currency ?? 'OMR',
            'card' => [
                'brand' => $payment->Card_Brand ?? null,
                'last4' => $payment->Card_Last4 ?? null,
                'exp_month' => $payment->Card_Exp_Month ?? null,
                'exp_year' => $payment->Card_Exp_Year ?? null,
                'gateway' => $payment->Card_Gateway ?? null,
                'transaction_id' => $payment->Card_Transaction_Id ?? null,
                'auth_code' => $payment->Card_Auth_Code ?? null,
            ],
            'transfer' => [
                'reference' => $payment->Transfer_Reference ?? null,
                'payer_name' => $payment->Transfer_Payer_Name ?? null,
                'bank_name' => $payment->Transfer_Bank_Name ?? null,
                'received_at' => $payment->Transfer_Received_At ?? null,
            ],
            'cod' => [
                'collected' => $payment->COD_Collected ?? null,
                'collected_at' => $payment->COD_Collected_At ?? null,
                'note' => $payment->COD_Note ?? null,
            ],
        ];
    }

    private function formatAddress(?object $address): ?array
    {
        if (!$address) {
            return null;
        }

        $titleName = null;
        if (!empty($address->Title_Id) && Schema::hasTable('Titles_Master_T')) {
            $titleName = DB::table('Titles_Master_T')
                ->where('id', $address->Title_Id)
                ->value('Title_Name');
        }

        return [
            'contact_name' => $address->Contact_Person_Name ?? null,
            'phone' => $address->Gsm ?? $address->Telephone ?? null,
            'email' => $address->Email ?? null,
            'title' => $titleName,
            // Prefer the DB-driven title; fall back to the legacy free-text
            // Designation for historical rows (do not break old data).
            'designation' => $titleName ?? $address->Designation ?? null,
            'remarks' => $address->Remarks ?? null,
            'country' => $this->geoName('Geox_Country_Master_T', $address->Country_Id ?? null, 'Country_Name'),
            'region' => $this->geoName('Geox_Region_Master_T', $address->Region_Id ?? null, 'Region_Name'),
            'district' => $this->geoName('Geox_District_Master_T', $address->District_Id ?? null, 'District_Name'),
            'city' => $this->geoName('Geox_City_Master_T', $address->City_Id ?? null, 'City_Name'),
        ];
    }

    private function geoName(string $table, mixed $id, string $column): ?string
    {
        if (!$id) {
            return null;
        }

        return DB::table($table)->where('id', $id)->value($column);
    }

    private function formatDestination(?object $destination): ?string
    {
        if (!$destination) {
            return null;
        }

        $parts = array_filter([
            $destination->Shippers_Destination_Country ?? null,
            $destination->Shippers_Destination_Region ?? null,
            $destination->Shippers_Destination_District ?? null,
            $destination->Shippers_Destination_Rate_Applicability ?? null,
        ]);

        return $parts ? implode(' / ', $parts) : null;
    }

    private function destinationMatchesAddress(object $destination, object $address): bool
    {
        $configured = false;
        $addressLocation = $this->resolveAddressLocation($address);

        $fields = [
            'Shippers_Destination_Country_Id' => 'country_id',
            'Shippers_Destination_Region_Id' => 'region_id',
            'Shippers_Destination_District_Id' => 'district_id',
        ];

        foreach ($fields as $destinationField => $addressField) {
            $destinationValue = $destination->{$destinationField} ?? null;

            if ($destinationValue === null || $destinationValue === '') {
                continue;
            }

            $configured = true;
            $addressValue = $addressLocation[$addressField] ?? null;

            if ($addressValue === null || (int) $destinationValue !== (int) $addressValue) {
                return false;
            }
        }

        return $configured;
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
