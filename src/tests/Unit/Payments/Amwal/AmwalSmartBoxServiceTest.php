<?php

namespace Tests\Unit\Payments\Amwal;

use App\Services\Payments\Amwal\AmwalSecureHash;
use App\Services\Payments\Amwal\AmwalSmartBoxService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AmwalSmartBoxServiceTest extends TestCase
{
    public function test_it_maps_and_signs_the_smartbox_configuration(): void
    {
        $service = new AmwalSmartBoxService(
            secureHash: new AmwalSecureHash('9FFA1F36D6E8A136482DF921E856709226DE5A974DB2673F84DB79DA788F7E19'),
            merchantId: '1369217',
            terminalId: '6942344',
            scriptUrl: 'https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1',
            currencyId: 512,
            paymentViewType: 1,
            contactInfoType: 4,
            ignoreReceipt: false,
        );

        $result = $service->configuration(
            amount: '36',
            merchantReference: '26_23122645',
            requestDateTime: '2023-12-26T09:42:46Z',
            language: 'AR',
        );

        $this->assertSame('https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1', $result['script_url']);
        $this->assertSame([
            'MID' => '1369217',
            'TID' => '6942344',
            'CurrencyId' => 512,
            'AmountTrxn' => '36.000',
            'MerchantReference' => '26_23122645',
            'LanguageId' => 'ar',
            'PaymentViewType' => 1,
            'TrxDateTime' => '2023-12-26T09:42:46Z',
            'SessionToken' => '',
            'ContactInfoType' => 4,
            'IgnoreReceipt' => 'false',
            'SecureHash' => '71B7B6EA4E13A575F2F5EFF0456A665D51A04CD3B09448158F167549B9CDBE84',
        ], $result['configuration']);
    }

    public function test_it_formats_omr_amounts_to_three_decimal_places(): void
    {
        $service = new AmwalSmartBoxService(
            new AmwalSecureHash(str_repeat('ab', 32)),
            'merchant',
            'terminal',
            'https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1',
        );

        $result = $service->configuration(1.0254, 'ORDER-1', '2026-07-12T08:00:00Z');

        $this->assertSame('1.025', $result['configuration']['AmountTrxn']);
    }

    public function test_it_rejects_invalid_amount_reference_date_and_language_values(): void
    {
        $service = new AmwalSmartBoxService(
            new AmwalSecureHash(str_repeat('ab', 32)),
            'merchant',
            'terminal',
            'https://test.amwalpg.com:7443/js/SmartBox.js?v=1.1',
        );

        $invalidCalls = [
            fn () => $service->configuration('not-a-number', 'ORDER-1', '2026-07-12T08:00:00Z'),
            fn () => $service->configuration('1.000', '', '2026-07-12T08:00:00Z'),
            fn () => $service->configuration('1.000', 'ORDER-1', ''),
            fn () => $service->configuration('1.000', 'ORDER-1', '2026-07-12T08:00:00Z', 'fr'),
        ];

        foreach ($invalidCalls as $call) {
            try {
                $call();
                $this->fail('Invalid SmartBox configuration should have been rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_accepts_only_the_exact_official_smartbox_script_endpoints(): void
    {
        $hash = new AmwalSecureHash(str_repeat('ab', 32));

        $production = new AmwalSmartBoxService(
            $hash,
            'merchant',
            'terminal',
            'https://checkout.amwalpg.com/js/SmartBox.js?v=1.1',
        );
        $this->assertSame(
            'https://checkout.amwalpg.com/js/SmartBox.js?v=1.1',
            $production->configuration('1.000', 'ORDER-1', '2026-07-12T08:00:00Z')['script_url'],
        );

        foreach ([
            'http://test.amwalpg.com:7443/js/SmartBox.js?v=1.1',
            'https://test.amwalpg.com/js/SmartBox.js?v=1.1',
            'https://test.amwalpg.com:7443/js/SmartBox.js?v=2',
            'https://checkout.amwalpg.com:7443/js/SmartBox.js?v=1.1',
            'https://checkout.amwalpg.com/js/SmartBox.js?v=1.1#unexpected',
        ] as $url) {
            try {
                new AmwalSmartBoxService($hash, 'merchant', 'terminal', $url);
                $this->fail("Unapproved SmartBox URL [{$url}] should have been rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
