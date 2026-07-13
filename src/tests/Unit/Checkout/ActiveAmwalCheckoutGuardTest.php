<?php

namespace Tests\Unit\Checkout;

use App\Services\Checkout\ActiveAmwalCheckoutGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActiveAmwalCheckoutGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('Orders_Placed_T');
        Schema::create('Orders_Placed_T', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('Customers_Id');
            $table->string('Order_Code')->nullable();
            $table->string('Payment_Method')->nullable();
            $table->string('Payment_Status')->nullable();
            $table->string('Status')->nullable();
        });
    }

    public function test_pending_card_checkout_blocks_cart_mutation(): void
    {
        $this->seedOrder(1, 'card', 'pending', 'pending');

        $order = app(ActiveAmwalCheckoutGuard::class)->blockingOrder(77);

        $this->assertNotNull($order);
        $this->assertSame(1, (int) $order->id);
    }

    public function test_terminal_or_captured_orders_do_not_block_cart_mutation(): void
    {
        $this->seedOrder(1, 'card', 'cancelled', 'cancelled');
        $this->seedOrder(2, 'card', 'paid', 'pending');
        $this->seedOrder(3, 'cod', 'pending', 'pending');

        $this->assertNull(app(ActiveAmwalCheckoutGuard::class)->blockingOrder(77));
    }

    private function seedOrder(
        int $id,
        string $method,
        string $paymentStatus,
        string $status,
    ): void {
        DB::table('Orders_Placed_T')->insert([
            'id' => $id,
            'Customers_Id' => 77,
            'Order_Code' => "ORD-{$id}",
            'Payment_Method' => $method,
            'Payment_Status' => $paymentStatus,
            'Status' => $status,
        ]);
    }
}
