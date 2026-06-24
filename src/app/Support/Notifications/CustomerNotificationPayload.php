<?php

namespace App\Support\Notifications;

final class CustomerNotificationPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function orderUpdate(int $orderId, ?string $orderCode, string $status): array
    {
        $label = self::statusLabel($status);
        $orderRef = $orderCode ?: "#{$orderId}";

        return [
            'category' => 'order_update',
            'title' => 'Order update',
            'message' => "Order {$orderRef} is now {$label}.",
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'status' => $status,
            'url' => "/account?tab=orders&order={$orderId}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function returnRefund(int $orderId, ?string $orderCode, int $returnedQuantity, string|int|float $refundedAmount): array
    {
        $orderRef = $orderCode ?: "#{$orderId}";
        $amount = number_format(max(0, (float) $refundedAmount), 3, '.', '');

        return [
            'category' => 'return_refund',
            'title' => 'Return/refund updated',
            'message' => "Return/refund activity was recorded for order {$orderRef}. Refunded OMR {$amount}.",
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'returned_quantity' => $returnedQuantity,
            'refunded_amount' => $amount,
            'url' => "/account?tab=orders&order={$orderId}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ticketReply(int $ticketId, ?string $reference, string $subject): array
    {
        $ticketRef = $reference ?: "#{$ticketId}";

        return [
            'category' => 'ticket_reply',
            'title' => 'Support replied',
            'message' => "Support replied to {$ticketRef}: {$subject}",
            'ticket_id' => $ticketId,
            'ticket_reference' => $reference,
            'subject' => $subject,
            'url' => "/account?tab=tickets&ticket={$ticketId}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function backInStock(int $productId, string $productName, ?string $slug): array
    {
        return [
            'category' => 'back_in_stock',
            'title' => 'Back in stock',
            'message' => "{$productName} is available again.",
            'product_id' => $productId,
            'product_name' => $productName,
            'url' => $slug ? "/product/{$slug}" : "/product/{$productId}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminMessage(string $title, string $message, ?string $url = null, array $meta = []): array
    {
        return array_filter([
            'category' => 'admin_message',
            'title' => $title,
            'message' => $message,
            'url' => $url,
            'meta' => $meta,
        ], fn ($value) => $value !== null);
    }

    private static function statusLabel(string $status): string
    {
        return str_replace('_', ' ', strtolower($status));
    }
}
