<?php

namespace App\Services\Payments\Amwal;

final class AmwalPaymentAttemptEnvironment
{
    public static function matches(?string $attemptEnvironment, ?string $configuredEnvironment): bool
    {
        $attempt = self::canonical($attemptEnvironment);

        // Older rows may predate environment metadata. Only an explicitly
        // different environment is rejected here.
        if ($attempt === null) {
            return true;
        }

        return $attempt === self::canonical($configuredEnvironment);
    }

    private static function canonical(?string $environment): ?string
    {
        $environment = strtolower(trim((string) $environment));

        return match ($environment) {
            'prod', 'production' => 'production',
            'uat' => 'uat',
            default => $environment !== '' ? $environment : null,
        };
    }
}
