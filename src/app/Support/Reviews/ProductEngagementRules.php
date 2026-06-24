<?php

namespace App\Support\Reviews;

final class ProductEngagementRules
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REPORTED = 'reported';

    /**
     * @param iterable<array<string, mixed>|object> $reviews
     * @return array{average_rating: string, review_count: int, distribution: array<int, int>}
     */
    public static function ratingSummary(iterable $reviews): array
    {
        $ratings = [];
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        foreach ($reviews as $review) {
            if (self::value($review, 'Status') !== self::STATUS_APPROVED) {
                continue;
            }

            $rating = (int) self::value($review, 'Rating');
            if ($rating < 1 || $rating > 5) {
                continue;
            }

            $ratings[] = $rating;
            $distribution[$rating]++;
        }

        $count = count($ratings);
        $average = $count ? array_sum($ratings) / $count : 0;

        return [
            'average_rating' => number_format($average, 2, '.', ''),
            'review_count' => $count,
            'distribution' => $distribution,
        ];
    }

    /**
     * @param iterable<array<string, mixed>|object> $items
     * @return list<int>
     */
    public static function publicVisibleIds(iterable $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (self::value($item, 'Status') === self::STATUS_APPROVED) {
                $ids[] = (int) self::value($item, 'id');
            }
        }

        return $ids;
    }

    /**
     * @param iterable<array<string, mixed>|object> $lines
     * @return array{verified: bool, orders_placed_id: int|null, orders_placed_details_id: int|null}
     */
    public static function verifiedPurchaseFromLines(iterable $lines, int $productId, int $customerId): array
    {
        foreach ($lines as $line) {
            if ((int) self::value($line, 'Products_Id') !== $productId) {
                continue;
            }

            if ((int) self::value($line, 'Customers_Id') !== $customerId) {
                continue;
            }

            $orderStatus = strtolower((string) self::value($line, 'Order_Status'));
            $detailStatus = strtolower((string) self::value($line, 'Detail_Status'));

            if (in_array($orderStatus, ['cancelled', 'failed', 'pending'], true)) {
                continue;
            }

            if (in_array($detailStatus, ['cancelled', 'pending'], true)) {
                continue;
            }

            if (
                in_array($orderStatus, ['delivered', 'completed', 'returned'], true)
                || in_array($detailStatus, ['delivered', 'returned'], true)
            ) {
                return [
                    'verified' => true,
                    'orders_placed_id' => (int) self::value($line, 'Orders_Placed_Id'),
                    'orders_placed_details_id' => (int) self::value($line, 'Orders_Placed_Details_Id'),
                ];
            }
        }

        return [
            'verified' => false,
            'orders_placed_id' => null,
            'orders_placed_details_id' => null,
        ];
    }

    private static function value(array|object $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return $source->{$key} ?? null;
    }
}
