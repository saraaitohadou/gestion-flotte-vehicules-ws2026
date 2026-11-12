<?php
namespace App\Exceptions;

class ServiceException extends \RuntimeException
{
    public function __construct(string $message, private int $status)
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public static function notFound(string $message = 'Véhicule introuvable'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }
}