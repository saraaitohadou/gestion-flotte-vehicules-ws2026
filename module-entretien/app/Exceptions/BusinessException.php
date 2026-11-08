<?php

namespace App\Exceptions;

use RuntimeException;

/** Erreur métier / technique renvoyée au client au format JSON structuré. */
class BusinessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $errorCode = 'BUSINESS_ERROR',
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
