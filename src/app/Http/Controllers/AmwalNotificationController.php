<?php

namespace App\Http\Controllers;

use App\Services\Payments\Amwal\AmwalPaymentException;
use App\Services\Payments\Amwal\AmwalPaymentProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use JsonException;

final class AmwalNotificationController extends Controller
{
    public function __invoke(Request $request)
    {
        if (!$this->isConfigured()) {
            return response()->json(['message' => 'unavailable', 'success' => false], 503);
        }

        $raw = $request->getContent();
        if (!$request->isJson() || strlen($raw) === 0 || strlen($raw) > 16384) {
            return response()->json(['message' => 'invalid notification', 'success' => false], 415);
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return response()->json(['message' => 'invalid notification', 'success' => false], 400);
        }

        if (!is_array($payload)) {
            return response()->json(['message' => 'invalid notification', 'success' => false], 400);
        }

        $payload = $this->preserveNumericLexemes($raw, $payload);

        $validator = validator($payload, [
            'MerchantId' => ['required', 'string', 'max:19'],
            'TerminalId' => ['required', 'string', 'max:19'],
            'AuthorizationDateTime' => ['required', 'string', 'regex:/^\d{14}$/'],
            'DateTimeLocalTrxn' => ['required', 'string', 'regex:/^\d{14}$/'],
            'ResponseCode' => ['nullable', 'string', 'max:20'],
            'TxnType' => ['required', 'string', 'max:50'],
            'PaidThrough' => ['nullable', 'string', 'max:50'],
            'SystemReference' => ['required', 'string', 'max:191'],
            'Message' => ['required', 'string', 'max:500'],
            'MerchantReference' => ['required', 'string', 'max:120'],
            'Amount' => ['required'],
            'CurrencyId' => ['required', 'string', 'size:3'],
        ]);

        if ($validator->fails() || $this->hashValue($payload) === null) {
            return response()->json(['message' => 'invalid notification', 'success' => false], 422);
        }

        try {
            app(AmwalPaymentProcessor::class)->processCloudNotification($payload);
        } catch (AmwalPaymentException $exception) {
            return response()->json([
                'message' => 'invalid notification',
                'success' => false,
            ], $exception->status);
        }

        return response()->json(['message' => 'success', 'success' => true]);
    }

    private function isConfigured(): bool
    {
        foreach (['merchant_id', 'terminal_id', 'secure_key'] as $key) {
            if (trim((string) config("services.amwal.{$key}")) === '') {
                return false;
            }
        }

        $secureKey = trim((string) config('services.amwal.secure_key'));
        if (strlen($secureKey) % 2 !== 0 || !ctype_xdigit($secureKey)) {
            return false;
        }

        return Schema::hasTable('Payment_Gateway_Attempts_T')
            && Schema::hasTable('Payment_Gateway_Events_T');
    }

    /**
     * Preserve the exact JSON number text used by Amwal when calculating its hash.
     * PHP otherwise turns 1.000 into a float and loses the signed representation.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function preserveNumericLexemes(string $raw, array $payload): array
    {
        foreach (['Amount', 'CurrencyId', 'MerchantId', 'TerminalId'] as $field) {
            $pattern = '/"'.preg_quote($field, '/').'"\s*:\s*(-?(?:0|[1-9]\d*)(?:\.\d+)?)/u';

            if (preg_match($pattern, $raw, $matches) === 1) {
                $payload[$field] = $matches[1];
            } elseif (isset($payload[$field]) && is_scalar($payload[$field])) {
                $payload[$field] = (string) $payload[$field];
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function hashValue(array $payload): ?string
    {
        foreach (['SecureHash', 'secureHashValue', 'SecureHashValue'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && preg_match('/^[A-Fa-f0-9]{64}$/', $payload[$key])) {
                return $payload[$key];
            }
        }

        return null;
    }
}
