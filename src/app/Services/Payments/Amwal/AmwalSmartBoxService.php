<?php

namespace App\Services\Payments\Amwal;

use InvalidArgumentException;

final class AmwalSmartBoxService
{
    public function __construct(
        private readonly AmwalSecureHash $secureHash,
        private readonly string $merchantId,
        private readonly string $terminalId,
        private readonly string $scriptUrl,
        private readonly int $currencyId = 512,
        private readonly int $paymentViewType = 1,
        private readonly int $contactInfoType = 1,
        private readonly bool $ignoreReceipt = false,
    ) {
        if (trim($this->merchantId) === '' || trim($this->terminalId) === '') {
            throw new InvalidArgumentException('Amwal merchant and terminal IDs are required.');
        }

        if (!$this->isOfficialScriptUrl($this->scriptUrl)) {
            throw new InvalidArgumentException('The Amwal SmartBox script URL is not an approved endpoint.');
        }

        if ($this->currencyId < 1) {
            throw new InvalidArgumentException('The Amwal currency ID must be positive.');
        }

        if (! in_array($this->paymentViewType, [1, 2], true)) {
            throw new InvalidArgumentException('The Amwal payment view type must be 1 or 2.');
        }

        if (! in_array($this->contactInfoType, [1, 2, 3, 4], true)) {
            throw new InvalidArgumentException('The Amwal contact info type must be between 1 and 4.');
        }
    }

    /**
     * @return array{script_url: string, configuration: array<string, int|string>}
     */
    public function configuration(
        string|int|float $amount,
        string $merchantReference,
        string $requestDateTime,
        string $language = 'en',
        ?string $sessionToken = null,
    ): array {
        $formattedAmount = $this->formatAmount($amount);
        $merchantReference = trim($merchantReference);
        $requestDateTime = trim($requestDateTime);
        $language = strtolower(trim($language));
        $sessionToken = $sessionToken ?? '';

        if ($merchantReference === '') {
            throw new InvalidArgumentException('The Amwal merchant reference is required.');
        }

        if ($requestDateTime === '') {
            throw new InvalidArgumentException('The Amwal request date and time is required.');
        }

        if (! in_array($language, ['en', 'ar'], true)) {
            throw new InvalidArgumentException('The Amwal language must be en or ar.');
        }

        $hashPayload = [
            'Amount' => $formattedAmount,
            'CurrencyId' => $this->currencyId,
            'MerchantId' => trim($this->merchantId),
            'MerchantReference' => $merchantReference,
            'RequestDateTime' => $requestDateTime,
            'SessionToken' => $sessionToken,
            'TerminalId' => trim($this->terminalId),
        ];

        return [
            'script_url' => $this->scriptUrl,
            'configuration' => [
                'MID' => trim($this->merchantId),
                'TID' => trim($this->terminalId),
                'CurrencyId' => $this->currencyId,
                'AmountTrxn' => $formattedAmount,
                'MerchantReference' => $merchantReference,
                'LanguageId' => $language,
                'PaymentViewType' => $this->paymentViewType,
                'TrxDateTime' => $requestDateTime,
                'SessionToken' => $sessionToken,
                'ContactInfoType' => $this->contactInfoType,
                'IgnoreReceipt' => $this->ignoreReceipt ? 'true' : 'false',
                'SecureHash' => $this->secureHash->requestHash($hashPayload),
            ],
        ];
    }

    private function formatAmount(string|int|float $amount): string
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('The Amwal amount must be numeric.');
        }

        $numericAmount = (float) $amount;

        if (! is_finite($numericAmount) || $numericAmount <= 0) {
            throw new InvalidArgumentException('The Amwal amount must be greater than zero.');
        }

        return number_format($numericAmount, 3, '.', '');
    }

    private function isOfficialScriptUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['path'] ?? null) !== '/js/SmartBox.js'
            || ($parts['query'] ?? null) !== 'v=1.1'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;

        return ($host === 'test.amwalpg.com' && $port === 7443)
            || ($host === 'checkout.amwalpg.com' && $port === null);
    }
}
