<?php

namespace Tests\Unit\Notifications;

use App\Support\Notifications\CustomerNotificationPayload;
use PHPUnit\Framework\TestCase;

class CustomerNotificationPayloadTest extends TestCase
{
    public function test_order_update_payload_contains_customer_facing_action(): void
    {
        $payload = CustomerNotificationPayload::orderUpdate(
            orderId: 42,
            orderCode: 'ORD-42',
            status: 'shipped',
        );

        $this->assertSame('order_update', $payload['category']);
        $this->assertSame(42, $payload['order_id']);
        $this->assertSame('ORD-42', $payload['order_code']);
        $this->assertSame('shipped', $payload['status']);
        $this->assertSame('/account?tab=orders&order=42', $payload['url']);
        $this->assertStringContainsString('ORD-42', $payload['message']);
    }

    public function test_ticket_reply_payload_links_to_requests_tab(): void
    {
        $payload = CustomerNotificationPayload::ticketReply(
            ticketId: 7,
            reference: 'TKT-123',
            subject: 'Need help',
        );

        $this->assertSame('ticket_reply', $payload['category']);
        $this->assertSame(7, $payload['ticket_id']);
        $this->assertSame('/account?tab=tickets&ticket=7', $payload['url']);
        $this->assertStringContainsString('TKT-123', $payload['message']);
    }

    public function test_back_in_stock_payload_links_to_product(): void
    {
        $payload = CustomerNotificationPayload::backInStock(
            productId: 9,
            productName: 'Cutting Disc',
            slug: 'cutting-disc',
        );

        $this->assertSame('back_in_stock', $payload['category']);
        $this->assertSame(9, $payload['product_id']);
        $this->assertSame('/product/cutting-disc', $payload['url']);
    }
}
