<?php

declare(strict_types=1);

namespace App\Services\Payments\Thawani;

use App\Models\CustomersContact;
use App\Models\CustomersMaster;
use App\Models\OrdersPlaced;
use App\Models\User;
use InvalidArgumentException;

/**
 * Builds the explicit customer/order metadata allowlist for Thawani.
 *
 * This class is deliberately disconnected from any HTTP client or route until
 * the merchant's UAT contract is confirmed. Callers must pass authenticated,
 * server-loaded records; browser-provided metadata must never be forwarded.
 */
final class ThawaniMetadataBuilder
{
    /**
     * @return array{
     *     customer_name: string,
     *     customer_email: string,
     *     customer_phone: string,
     *     customer_id: string,
     *     order_id: string
     * }
     */
    public function build(
        User $user,
        CustomersMaster $customer,
        OrdersPlaced $order,
        ?CustomersContact $contact = null,
    ): array {
        $name = $this->required('customer name', [
            $customer->Customer_Full_Name,
            $contact?->Contact_Person_Name,
            $user->User_Name,
        ]);

        $email = strtolower($this->required('customer email', [
            $user->email,
            $contact?->Email,
        ]));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid customer email is required for Thawani checkout.');
        }

        $phone = $this->phone(
            countryCode: $this->optional([
                $contact?->Telephone_Country_Code,
                $customer->Telephone_Country_Code,
            ]),
            number: $this->required('customer phone', [
                $contact?->Gsm,
                $contact?->Telephone,
                $customer->Telephone,
            ]),
        );

        $customerReference = $this->required('customer reference', [
            $customer->Customer_Code,
            $customer->getKey(),
        ]);

        $orderReference = $this->required('order reference', [
            $order->Order_Code,
            $order->getKey(),
        ]);

        return [
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'customer_id' => $customerReference,
            'order_id' => $orderReference,
        ];
    }

    /**
     * @param  array<mixed>  $candidates
     */
    private function required(string $field, array $candidates): string
    {
        $value = $this->optional($candidates);

        if ($value === null) {
            throw new InvalidArgumentException("A {$field} is required for Thawani checkout.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $candidates
     */
    private function optional(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = preg_replace('/\s+/u', ' ', trim((string) $candidate));

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function phone(?string $countryCode, string $number): string
    {
        $numberHasInternationalPrefix = str_starts_with(ltrim($number), '+');
        $numberDigits = preg_replace('/\D+/', '', $number) ?? '';

        if ($numberDigits === '') {
            throw new InvalidArgumentException('A valid customer phone is required for Thawani checkout.');
        }

        if ($numberHasInternationalPrefix) {
            return "+{$numberDigits}";
        }

        $countryDigits = preg_replace('/\D+/', '', (string) $countryCode) ?? '';
        if ($countryDigits === '') {
            return $numberDigits;
        }

        if (str_starts_with($numberDigits, $countryDigits)) {
            return "+{$numberDigits}";
        }

        return "+{$countryDigits}{$numberDigits}";
    }
}
