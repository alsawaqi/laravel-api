<?php

namespace App\Services\Checkout;

final class CheckoutIdempotency
{
    /**
     * @param iterable<array<string, mixed>|object> $cartRows
     * @param array<string, mixed> $payload
     */
    public static function fingerprint(int $customerId, iterable $cartRows, array $payload): string
    {
        $cart = [];

        foreach ($cartRows as $row) {
            $cart[] = [
                'id' => self::value($row, 'id'),
                'product_id' => self::value($row, 'Products_Id'),
                'quantity' => (int) self::value($row, 'Quantity'),
            ];
        }

        usort($cart, fn (array $a, array $b) => [$a['product_id'], $a['id']] <=> [$b['product_id'], $b['id']]);

        $material = [
            'v' => 1,
            'customer_id' => $customerId,
            'cart' => $cart,
            'delivery_method' => $payload['delivery_method'] ?? null,
            'location_id' => $payload['location_id'] ?? null,
            'contact_id' => $payload['Customers_Contacts_Id'] ?? null,
            'shipping_option' => self::onlyKeys($payload['shipping_option'] ?? [], [
                'shipper_id',
                'destination_id',
                'basis',
                'price',
                'currency',
            ]),
            'payment_method' => $payload['payment']['method'] ?? null,
            'loyalty' => self::onlyKeys($payload['loyalty'] ?? [], [
                'use_points',
                'points',
            ]),
        ];

        return hash('sha256', json_encode($material, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    public static function requestKey(?string $headerValue, mixed $inputValue): ?string
    {
        $value = trim((string) ($headerValue ?: $inputValue ?: ''));

        if ($value === '') {
            return null;
        }

        return substr(preg_replace('/[^A-Za-z0-9:_-]/', '', $value) ?: '', 0, 120) ?: null;
    }

    private static function value(array|object $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    /**
     * @param mixed $values
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    private static function onlyKeys(mixed $values, array $keys): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $values[$key] ?? null;
        }

        return $result;
    }
}
