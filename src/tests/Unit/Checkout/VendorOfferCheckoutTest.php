<?php

namespace Tests\Unit\Checkout;

use App\Http\Controllers\CustomerCartController;
use App\Http\Controllers\OrdersPlacedController;
use App\Models\CustomerCart;
use App\Models\ProductVendorOffer;
use App\Services\Checkout\PaymentGateway;
use App\Services\Checkout\PendingPaymentGateway;
use App\Services\Orders\CustomerUnpaidAmwalOrderCancellationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VendorOfferCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'offers_test', 'database.connections.offers_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('offers_test'); DB::setDefaultConnection('offers_test');
        Event::fake(); Mail::fake();
        $this->app->instance(PaymentGateway::class, new PendingPaymentGateway);
        $tables = [
            'Customers_Master_T' => '', 'Geox_Location_Master_T' => '', 'Vat_Master_T' => 'Vat',
            'Vendors_Master_T' => 'Vendor_Name Is_Active',
            'Products_Master_T' => 'Product_Name Vendor_Id Product_Price Product_Cost Minimum_Selling_Price Product_Stock Status Is_Active Commission_Type Commission_Value Slug',
            'Products_Vendor_Offers_T' => 'Products_Id Vendor_Id Product_Price Product_Cost Minimum_Selling_Price Product_Stock Status Is_Active Commission_Type Commission_Value',
            'Products_Vendor_Offer_Bulk_Prices_T' => 'Vendor_Offer_Id Min_Qty Max_Qty Unit_Price',
            'Products_Bulk_Prices_T' => 'Products_Id Min_Qty Max_Qty Unit_Price',
            'Products_Images_T' => 'Products_Id Image_Path',
            'Customers_Carts_T' => 'Customers_Id Products_Id Vendor_Offer_Id Quantity',
            'Customers_Loyalty_T' => 'Customer_Id Customers_Loyalty_Code Points_Earned Points_Redeemed',
            'System_Parameter_Loyalty_Points_T' => 'Point Earn_Amount Earn_Points',
            'Orders_Placed_T' => 'Order_Code Customers_Contacts_Id Transaction_Number Customers_Id Delivery_Type Location_Id Total_Price Status VAT Sub_Total_Price Shippers_Id Shippers_Destination_Id Shipping_Basis Shipping_Price Shipping_Currency Shipping_Weight_Kg Shipping_Volume_Cbm Loyalty_Points_Redeemed Loyalty_Discount_Amount Checkout_Request_Key Checkout_Submitted_At Payment_Status Payment_Method',
            'Orders_Placed_Details_T' => 'Order_Placed_Code Orders_Placed_Id Cart_Id Products_Id Vendor_Offer_Id Quantity Price Subtotal Vat Vendor_Id Orders_Placed_Vendor_Id Status Commission_Type Commission_Value Commission_Amount',
            'Orders_Placed_Vendors_T' => 'Orders_Placed_Id Vendor_Id Vendor_Order_Code Sub_Total VAT Shipping Total Status Commission_Type Commission_Value Commission_Amount Payout_Status Commission_Source Returned_Quantity Refunded_Amount Net_Sub_Total Adjusted_Commission_Amount Net_Payout_Amount Payout_Adjustment_Amount',
            'Sales_Transaction_Header_T' => 'Sales_Transaction_Header_code Bill_No Orders_Placed_Id',
            'Sales_Transactions_Details_T' => 'Sales_Transactions_Details_code Sales_Transaction_Header_Id Transaction_No Merchant_Id Bill_No Discount_Amount VAT_Tax_Amount Transaction_Date Payment_Method Payment_Status Payment_Amount Payment_Currency Payment_Gateway Payment_Intent_Id Payment_Idempotency_Key Payment_Metadata Card_Brand Card_Last4 Card_Exp_Month Card_Exp_Year Card_Transaction_Id Card_Error_Code Card_Error_Message COD_Collected COD_Collected_At COD_Note',
        ];
        foreach ($tables as $name => $columns) {
            Schema::create($name, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach (array_filter(explode(' ', $columns)) as $column) {
                    if (str_ends_with($column, '_Id') || in_array($column, ['Product_Stock', 'Quantity', 'Min_Qty', 'Max_Qty', 'Is_Active'])) {
                        $t->integer($column)->nullable();
                    } else { $t->text($column)->nullable(); }
                }
                $t->timestamps(); $t->softDeletes();
            });
        }
        DB::table('Customers_Master_T')->insert(['id' => 77]);
        DB::table('Geox_Location_Master_T')->insert(['id' => 1]);
        DB::table('Vat_Master_T')->insert(['Vat' => 0.05]);
        DB::table('Vendors_Master_T')->insert([['id' => 1, 'Vendor_Name' => 'Seller A', 'Is_Active' => 1], ['id' => 2, 'Vendor_Name' => 'Seller B', 'Is_Active' => 1]]);
        DB::table('Products_Master_T')->insert(['id' => 1, 'Product_Name' => 'Drill', 'Vendor_Id' => 1, 'Product_Price' => 999, 'Product_Stock' => 100, 'Status' => 'available', 'Is_Active' => 1]);
        foreach ([1 => [10.123, 7, 1], 2 => [15.789, 20, 2.5]] as $vendor => [$price, $stock, $commission]) {
            ProductVendorOffer::create(['id' => $vendor, 'Products_Id' => 1, 'Vendor_Id' => $vendor, 'Product_Price' => $price, 'Product_Stock' => $stock, 'Status' => 'available', 'Is_Active' => 1, 'Commission_Type' => 'fixed', 'Commission_Value' => $commission]);
        }
        // The canonical tier must never leak into either vendor's offer.
        DB::table('Products_Bulk_Prices_T')->insert(['Products_Id' => 1, 'Min_Qty' => 1, 'Unit_Price' => 0.001]);
        DB::table('Products_Vendor_Offer_Bulk_Prices_T')->insert(['Vendor_Offer_Id' => 2, 'Min_Qty' => 3, 'Unit_Price' => 14.123]);
        $customer = new class {
            public int $id = 700;
            public function __get($key) { return $key === 'customers' ? (object) ['id' => 77] : null; }
            public function customerOrCreate() { return (object) ['id' => 77]; }
        };
        Auth::shouldReceive('user')->andReturn($customer);
        Auth::shouldReceive('id')->andReturn(700);
    }

    protected function tearDown(): void
    {
        DB::purge('offers_test'); parent::tearDown();
    }

    private function cart(int $offer, int $qty): void
    {
        (new CustomerCartController)->add(Request::create('/api/cart/add', 'POST', ['product_id' => 1, 'vendor_offer_id' => $offer, 'quantity' => $qty]));
    }

    private function checkout()
    {
        return (new OrdersPlacedController)->place(Request::create('/api/orders/place', 'POST', [
            'delivery_method' => 'pickup', 'location_id' => 1, 'shipping_cost' => 0,
            'payment' => ['method' => 'card', 'currency' => 'OMR', 'amount' => 0.001],
            'total' => ['grand' => 0.001], 'idempotency_key' => 'offer-checkout',
        ]));
    }

    public function test_two_sellers_remain_separate_through_sync_update_and_remove(): void
    {
        $controller = new CustomerCartController;
        $controller->sync(Request::create('/api/cart/sync', 'POST', ['items' => [
            ['product_id' => 1, 'vendor_offer_id' => 1, 'quantity' => 2],
            ['product_id' => 1, 'vendor_offer_id' => 2, 'quantity' => 3],
        ]]));
        $this->assertSame(2, CustomerCart::count());
        $controller->setQuantity(Request::create('/api/cart/item', 'POST', ['product_id' => 1, 'vendor_offer_id' => 2, 'quantity' => 5]));
        $this->assertEquals(2, CustomerCart::where('Vendor_Offer_Id', 1)->value('Quantity'));
        $this->assertEquals(5, CustomerCart::where('Vendor_Offer_Id', 2)->value('Quantity'));
        $controller->remove(Request::create('/api/cart/item/1?vendor_offer_id=1', 'DELETE'), 1);
        $this->assertSame(1, CustomerCart::count());
        $this->assertEquals(2, CustomerCart::first()->Vendor_Offer_Id);
    }

    public function test_checkout_uses_selected_prices_stock_tiers_and_commission_in_separate_vendor_orders(): void
    {
        $this->cart(1, 2); $this->cart(2, 3);
        $response = $this->checkout();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $lines = DB::table('Orders_Placed_Details_T')->orderBy('Vendor_Id')->get();
        $this->assertCount(2, $lines);
        $this->assertEquals([1, 1], $lines->pluck('Products_Id')->all());
        $this->assertEquals([1, 2], $lines->pluck('Vendor_Offer_Id')->all());
        $this->assertEquals([10.123, 14.123], $lines->pluck('Price')->all());
        $this->assertEquals([2, 7.5], $lines->pluck('Commission_Amount')->all());
        $headers = DB::table('Orders_Placed_Vendors_T')->orderBy('Vendor_Id')->get();
        $this->assertCount(2, $headers);
        $this->assertEquals([18.246, 34.869], $headers->pluck('Net_Payout_Amount')->all());
        $this->assertEquals(65.746, $response->getData(true)['totals']['grand']);
        $this->assertSame(5, ProductVendorOffer::find(1)->Product_Stock);
        $this->assertSame(17, ProductVendorOffer::find(2)->Product_Stock);
        $this->assertEquals(100, DB::table('Products_Master_T')->value('Product_Stock'));
        $this->assertSame(0, CustomerCart::count());
    }

    public function test_cancellation_releases_each_offer_once_and_restores_two_cart_lines(): void
    {
        $this->cart(1, 2); $this->cart(2, 3);
        $response = $this->checkout();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        DB::table('Sales_Transactions_Details_T')->update(['Payment_Gateway' => 'amwal_smartbox']);
        $service = new CustomerUnpaidAmwalOrderCancellationService;
        $orderId = $response->getData(true)['order_id'];
        $result = $service->cancel($orderId, 77, 700);
        $this->assertEquals(2, $result['cart_restoration']['restored_lines']);
        $this->assertEquals([2, 3], CustomerCart::orderBy('Vendor_Offer_Id')->pluck('Quantity')->all());
        $this->assertSame(7, ProductVendorOffer::find(1)->Product_Stock);
        $this->assertSame(20, ProductVendorOffer::find(2)->Product_Stock);
        $this->assertTrue($service->cancel($orderId, 77, 700)['idempotent']);
        $this->assertSame(7, ProductVendorOffer::find(1)->Product_Stock);
        $this->assertSame(2, CustomerCart::count());
    }

    public function test_invalid_offer_cannot_be_added_to_another_product(): void
    {
        ProductVendorOffer::find(2)->update(['Products_Id' => 99]);
        $this->expectException(ValidationException::class); $this->cart(2, 1);
    }

    public function test_disabled_offer_blocks_checkout_without_taking_other_sellers_stock(): void
    {
        $this->cart(1, 2); $this->cart(2, 3);
        ProductVendorOffer::find(2)->update(['Is_Active' => false]);
        try { $this->checkout(); $this->fail('Expected unavailable seller'); }
        catch (ValidationException) { $this->assertSame(0, DB::table('Orders_Placed_T')->count()); }
        $this->assertSame(7, ProductVendorOffer::find(1)->Product_Stock);
        $this->assertSame(2, CustomerCart::count());
    }

    public function test_overselling_one_vendor_rolls_back_the_entire_checkout(): void
    {
        $this->cart(1, 2); $this->cart(2, 21);
        $response = $this->checkout();
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Insufficient stock', $response->getContent());
        $this->assertSame(0, DB::table('Orders_Placed_T')->count());
        $this->assertSame(7, ProductVendorOffer::find(1)->Product_Stock);
        $this->assertSame(20, ProductVendorOffer::find(2)->Product_Stock);
    }
}
