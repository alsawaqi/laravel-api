<?php

declare(strict_types=1);

namespace Tests\Unit\Payments\Thawani;

use App\Services\Payments\Thawani\ThawaniMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThawaniMoneyTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_it_converts_omr_to_baisa_exactly(string|int $amount, int $expected): void
    {
        $this->assertSame($expected, ThawaniMoney::omrToBaisa($amount));
    }

    /**
     * @return array<string, array{string|int, int}>
     */
    public static function validAmounts(): array
    {
        return [
            'zero' => ['0', 0],
            'one baisa' => ['0.001', 1],
            'one hundred five baisa' => ['0.105', 105],
            'sixteen rials and fifty baisa' => ['16.050', 16050],
            'whole integer' => [16, 16000],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_ambiguous_or_invalid_amounts(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        ThawaniMoney::omrToBaisa($amount);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'negative' => ['-1.000'],
            'more than three decimals' => ['1.0001'],
            'not numeric' => ['sixteen'],
            'scientific notation' => ['1e3'],
            'empty' => [''],
        ];
    }
}
