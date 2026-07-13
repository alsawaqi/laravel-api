<?php

namespace Tests\Unit\Payments\Amwal;

use App\Services\Payments\Amwal\AmwalSecureHash;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AmwalSecureHashTest extends TestCase
{
    private const DOCUMENTED_REQUEST_KEY = '64373939653761352D343730352D343666632D623264312D3436323532346361615564654';

    private const DOCUMENTED_REQUEST_HASH = '8A8E9F1BC2979D6D89A947008831199E76331689D5B28D41395FA1DA65FFDE7B';

    private const DOCUMENTED_CALLBACK_HASH = '4E21F6F06C188F38B0B6A3CD2EFD9DC93EE83E3D5284C708B1C8930D9BCF0D11';

    public function test_it_builds_the_exact_documented_request_canonical_string(): void
    {
        $hash = new AmwalSecureHash(str_repeat('0b', 20));

        $this->assertSame(
            'Amount=10&CurrencyId=512&MerchantId=48804&MerchantReference=&RequestDateTime=121123103839&SessionToken=&TerminalId=113176',
            $hash->canonicalRequest([
                'TerminalId' => '113176',
                'CurrencyId' => 512,
                'Amount' => 10,
                'MerchantId' => '48804',
                'RequestDateTime' => '121123103839',
                'MerchantReference' => '',
                'ignored' => 'not-signed',
            ]),
        );
    }

    public function test_the_published_request_hash_vector_is_not_executable_because_its_key_is_malformed(): void
    {
        // Amwal publishes the expected hash below, but the supplied key has 73
        // hexadecimal characters. hex2bin requires complete byte pairs, so accepting
        // this key would silently change the gateway's documented algorithm.
        $this->assertSame(73, strlen(self::DOCUMENTED_REQUEST_KEY));
        $this->assertMatchesRegularExpression('/\A[0-9A-F]{64}\z/', self::DOCUMENTED_REQUEST_HASH);

        $this->expectException(InvalidArgumentException::class);

        new AmwalSecureHash(self::DOCUMENTED_REQUEST_KEY);
    }

    public function test_it_matches_the_well_formed_request_example_from_amwal_documentation(): void
    {
        $hash = new AmwalSecureHash('9FFA1F36D6E8A136482DF921E856709226DE5A974DB2673F84DB79DA788F7E19');

        $payload = [
            'Amount' => '36',
            'CurrencyId' => '512',
            'MerchantId' => '1369217',
            'MerchantReference' => '26_23122645',
            'RequestDateTime' => '2023-12-26T09:42:46Z',
            'SessionToken' => '',
            'TerminalId' => '6942344',
        ];

        $this->assertSame(
            '6C52CCFDD513FE12D62F98CFBC66799D6F54EE2D4B32E309A50CD46936AFD325',
            $hash->requestHash($payload),
        );
    }

    public function test_it_builds_the_exact_documented_callback_canonical_string(): void
    {
        $hash = new AmwalSecureHash(str_repeat('0b', 20));

        $payload = [
            'amount' => '1',
            'currencyId' => '512',
            'customerId' => '82383bce-6e32-4f5b-b1ea-7e00d5c446ed',
            'customerTokenId' => 'aacd0817-2246-4521-a3df-9f3971c63a22',
            'merchantId' => '7921',
            'merchantReference' => '201204',
            'responseCode' => '00',
            'terminalId' => '221143',
            'transactionId' => '6b75efb6-84ab-46f2-8a32-351a23490f45',
            'transactionTime' => '2024-12-10T15:56:37.1099636Z',
        ];

        $this->assertSame(
            'amount=1&currencyId=512&customerId=82383bce-6e32-4f5b-b1ea-7e00d5c446ed&customerTokenId=aacd0817-2246-4521-a3df-9f3971c63a22&merchantId=7921&merchantReference=201204&responseCode=00&terminalId=221143&transactionId=6b75efb6-84ab-46f2-8a32-351a23490f45&transactionTime=2024-12-10T15:56:37.1099636Z',
            $hash->canonicalCallback($payload),
        );

        // The documentation publishes this result but does not publish the callback
        // merchant key, so it cannot be reproduced without inventing key material.
        $this->assertMatchesRegularExpression('/\A[0-9A-F]{64}\z/', self::DOCUMENTED_CALLBACK_HASH);
    }

    public function test_it_includes_every_cloud_field_and_uses_empty_strings_for_missing_values(): void
    {
        $hash = new AmwalSecureHash(str_repeat('0b', 20));

        $this->assertSame(
            'Amount=1.025&AuthorizationDateTime=&CurrencyId=512&DateTimeLocalTrxn=&MerchantId=100&MerchantReference=ORDER-1&Message=&PaidThrough=&ResponseCode=00&SystemReference=&TerminalId=200&TxnType=Purchase',
            $hash->canonicalCloudNotification([
                'TxnType' => 'Purchase',
                'ResponseCode' => '00',
                'MerchantReference' => 'ORDER-1',
                'Amount' => '1.025',
                'CurrencyId' => '512',
                'MerchantId' => '100',
                'TerminalId' => '200',
            ]),
        );
    }

    public function test_it_verifies_supported_hash_aliases_and_rejects_tampering(): void
    {
        $hash = new AmwalSecureHash(str_repeat('ab', 32));
        $callback = [
            'amount' => '1.000',
            'currencyId' => '512',
            'customerId' => '',
            'customerTokenId' => '',
            'merchantId' => '100',
            'merchantReference' => 'ORDER-1',
            'responseCode' => '00',
            'terminalId' => '200',
            'transactionId' => 'TX-1',
            'transactionTime' => '2026-07-12T08:00:00Z',
        ];
        $expected = $hash->callbackHash($callback);

        $this->assertTrue($hash->verifyCallback($callback + ['secureHashValue' => strtolower($expected)]));
        $this->assertTrue($hash->verifyCallback($callback + ['SecureHash' => $expected]));
        $this->assertTrue($hash->verifyCallback($callback + ['secure_hash_value' => $expected]));

        $tampered = $callback;
        $tampered['amount'] = '2.000';
        $this->assertFalse($hash->verifyCallback($tampered + ['SecureHashValue' => $expected]));
        $this->assertFalse($hash->verifyCallback($callback));
        $this->assertFalse($hash->verifyCallback($callback + [
            'SecureHash' => $expected,
            'secureHashValue' => str_repeat('0', 64),
        ]));
    }

    public function test_it_rejects_empty_odd_length_and_non_hexadecimal_keys(): void
    {
        foreach (['', 'abc', 'not-hex!'] as $key) {
            try {
                new AmwalSecureHash($key);
                $this->fail("Key [{$key}] should have been rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
