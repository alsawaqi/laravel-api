<?php

namespace App\Support\Policies;

final class PolicyPageCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::shipping(),
            self::returns(),
            self::privacy(),
            self::terms(),
            self::warranty(),
            self::faq(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return list<array{slug: string, title: string, summary: string}>
     */
    public static function summaries(): array
    {
        return array_map(fn (array $page) => [
            'slug' => $page['slug'],
            'title' => $page['title'],
            'summary' => $page['summary'],
        ], self::all());
    }

    private static function shipping(): array
    {
        return [
            'slug' => 'shipping',
            'title' => 'Shipping Policy',
            'summary' => 'Delivery methods, shipping quote validity, pickup, and handover expectations.',
            'sections' => [
                ['heading' => 'Shipping quotes', 'body' => 'Shipping costs are calculated from the selected address, carrier destination, package weight, and package volume. Quotes may expire and can be recalculated before order placement.'],
                ['heading' => 'Delivery timing', 'body' => 'Delivery timing depends on stock availability, dispatch readiness, carrier capacity, and destination coverage. Customers receive updates as the order moves through packing, dispatch, shipment, and delivery.'],
                ['heading' => 'Pickup orders', 'body' => 'Pickup orders can be collected after the order is marked ready for collection. A customer or authorized representative may be asked to provide order details at handover.'],
            ],
        ];
    }

    private static function returns(): array
    {
        return [
            'slug' => 'returns',
            'title' => 'Returns & Refunds Policy',
            'summary' => 'How return requests, partial refunds, restocking, and support tickets are handled.',
            'sections' => [
                ['heading' => 'Starting a return', 'body' => 'Customers can open a return/refund request from the account requests area. Include the related order number, product, quantity, and reason.'],
                ['heading' => 'Partial returns', 'body' => 'Returns and refunds can be handled per order line. A refund may apply to selected products only and may be processed with or without physical return depending on the case.'],
                ['heading' => 'Inspection and approval', 'body' => 'Returned products may require inspection before restocking or final approval. Refund values are recorded against the affected order items for accurate net sales reporting.'],
            ],
        ];
    }

    private static function privacy(): array
    {
        return [
            'slug' => 'privacy',
            'title' => 'Privacy Policy',
            'summary' => 'How customer, account, order, notification, and support information is used.',
            'sections' => [
                ['heading' => 'Information collected', 'body' => 'We collect account, contact, address, order, loyalty, notification, and support information needed to operate the ecommerce service.'],
                ['heading' => 'Use of information', 'body' => 'Information is used to authenticate customers, process orders, calculate delivery, manage support, send service notifications, and improve customer experience.'],
                ['heading' => 'Data protection', 'body' => 'Access to customer records is limited to authorized operational users and systems that need the information to provide the service.'],
            ],
        ];
    }

    private static function terms(): array
    {
        return [
            'slug' => 'terms',
            'title' => 'Terms of Use',
            'summary' => 'Customer account, order, catalog, pricing, and acceptable-use terms.',
            'sections' => [
                ['heading' => 'Account use', 'body' => 'Customers are responsible for keeping account credentials secure and ensuring submitted contact, delivery, and payment details are accurate.'],
                ['heading' => 'Catalog and pricing', 'body' => 'Product availability, prices, promotions, shipping quotes, and order acceptance can change before an order is confirmed.'],
                ['heading' => 'Order acceptance', 'body' => 'Order submission records a request for fulfillment. Operational review may still be required for stock, delivery, or payment verification.'],
            ],
        ];
    }

    private static function warranty(): array
    {
        return [
            'slug' => 'warranty',
            'title' => 'Warranty Policy',
            'summary' => 'Warranty request expectations for industrial products and supplied goods.',
            'sections' => [
                ['heading' => 'Coverage', 'body' => 'Warranty coverage depends on manufacturer terms, product category, correct use, and the condition of the returned item.'],
                ['heading' => 'Claims', 'body' => 'Warranty claims should be opened from the support requests area with the order number, product details, issue description, and supporting photos where available.'],
                ['heading' => 'Exclusions', 'body' => 'Consumables, misuse, incorrect installation, abnormal operating conditions, and normal wear may be excluded from warranty coverage.'],
            ],
        ];
    }

    private static function faq(): array
    {
        return [
            'slug' => 'faq',
            'title' => 'FAQ',
            'summary' => 'Common questions about orders, delivery, returns, warranty, and account notifications.',
            'sections' => [
                ['heading' => 'Where can I track my order?', 'body' => 'Order status and details are available from the account orders area. Important updates also appear in the notification center.'],
                ['heading' => 'How do I request a return or refund?', 'body' => 'Open a return/refund request from the account requests area and include the related order and product details.'],
                ['heading' => 'How do back-in-stock alerts work?', 'body' => 'When an unavailable product becomes available again, customers who requested an alert receive a notification with a link back to the product page.'],
            ],
        ];
    }
}
