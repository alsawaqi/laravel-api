<?php

namespace App\Services\Payments\Amwal;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class AmwalPaymentException extends HttpException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($status, $message);
    }
}
