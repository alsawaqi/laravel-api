<?php

namespace App\Services\Payments\Amwal;

use InvalidArgumentException;

final class AmwalSecureHash
{
    /** @var list<string> */
    private const REQUEST_FIELDS = [
        'Amount',
        'CurrencyId',
        'MerchantId',
        'MerchantReference',
        'RequestDateTime',
        'SessionToken',
        'TerminalId',
    ];

    /** @var list<string> */
    private const CALLBACK_FIELDS = [
        'amount',
        'currencyId',
        'customerId',
        'customerTokenId',
        'merchantId',
        'merchantReference',
        'responseCode',
        'terminalId',
        'transactionId',
        'transactionTime',
    ];

    /** @var list<string> */
    private const CLOUD_NOTIFICATION_FIELDS = [
        'Amount',
        'AuthorizationDateTime',
        'CurrencyId',
        'DateTimeLocalTrxn',
        'MerchantId',
        'MerchantReference',
        'Message',
        'PaidThrough',
        'ResponseCode',
        'SystemReference',
        'TerminalId',
        'TxnType',
    ];

    private readonly string $binaryKey;

    public function __construct(string $hexKey)
    {
        $hexKey = trim($hexKey);

        if ($hexKey === '' || strlen($hexKey) % 2 !== 0 || ! ctype_xdigit($hexKey)) {
            throw new InvalidArgumentException('The Amwal secure key must be a non-empty, even-length hexadecimal string.');
        }

        $binaryKey = hex2bin($hexKey);

        if ($binaryKey === false) {
            throw new InvalidArgumentException('The Amwal secure key is not valid hexadecimal.');
        }

        $this->binaryKey = $binaryKey;
    }

    /** @param array<string, mixed> $payload */
    public function requestHash(array $payload): string
    {
        return $this->hash($this->canonicalRequest($payload));
    }

    /** @param array<string, mixed> $payload */
    public function verifyRequest(array $payload): bool
    {
        return $this->verify($payload, fn (): string => $this->requestHash($payload));
    }

    /** @param array<string, mixed> $payload */
    public function callbackHash(array $payload): string
    {
        return $this->hash($this->canonicalCallback($payload));
    }

    /** @param array<string, mixed> $payload */
    public function verifyCallback(array $payload): bool
    {
        return $this->verify($payload, fn (): string => $this->callbackHash($payload));
    }

    /** @param array<string, mixed> $payload */
    public function cloudNotificationHash(array $payload): string
    {
        return $this->hash($this->canonicalCloudNotification($payload));
    }

    /** @param array<string, mixed> $payload */
    public function verifyCloudNotification(array $payload): bool
    {
        return $this->verify($payload, fn (): string => $this->cloudNotificationHash($payload));
    }

    /** @param array<string, mixed> $payload */
    public function canonicalRequest(array $payload): string
    {
        return $this->canonical($payload, self::REQUEST_FIELDS);
    }

    /** @param array<string, mixed> $payload */
    public function canonicalCallback(array $payload): string
    {
        return $this->canonical($payload, self::CALLBACK_FIELDS);
    }

    /** @param array<string, mixed> $payload */
    public function canonicalCloudNotification(array $payload): string
    {
        return $this->canonical($payload, self::CLOUD_NOTIFICATION_FIELDS);
    }

    /**
     * Accepts the hash names used across Amwal's SmartBox, secure-hash, and
     * cloud-notification documentation. Conflicting duplicate aliases fail closed.
     *
     * @param array<string, mixed> $payload
     */
    public function extractHash(array $payload): ?string
    {
        $hash = null;

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));

            if (! in_array($normalizedKey, ['securehash', 'securehashvalue'], true)) {
                continue;
            }

            if (! is_string($value) || ! preg_match('/\A[0-9a-f]{64}\z/i', $value)) {
                return null;
            }

            $candidate = strtoupper($value);

            if ($hash !== null && ! hash_equals($hash, $candidate)) {
                return null;
            }

            $hash = $candidate;
        }

        return $hash;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     */
    private function canonical(array $payload, array $fields): string
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field] = $this->stringValue($payload[$field] ?? null, $field);
        }

        ksort($values, SORT_STRING);

        $pairs = [];

        foreach ($values as $field => $value) {
            $pairs[] = $field.'='.$value;
        }

        return implode('&', $pairs);
    }

    private function stringValue(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException("Amwal hash field [{$field}] must be a string or finite number.");
    }

    private function hash(string $canonical): string
    {
        return strtoupper(hash_hmac('sha256', $canonical, $this->binaryKey));
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(): string $calculate
     */
    private function verify(array $payload, callable $calculate): bool
    {
        $receivedHash = $this->extractHash($payload);

        if ($receivedHash === null) {
            return false;
        }

        try {
            return hash_equals($calculate(), $receivedHash);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
