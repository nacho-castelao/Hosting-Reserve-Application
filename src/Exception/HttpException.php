<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;
use Throwable;

/**
 * Excepción que se traduce directamente a una respuesta HTTP.
 * Su mensaje se considera seguro para mostrarse al cliente.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers Cabeceras extra de la respuesta (p. ej. Allow).
     */
    public function __construct(
        private readonly int $statusCode,
        string $message,
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Errores por campo (solo los rellenan las excepciones de validación).
     *
     * @return array<string, string>
     */
    public function getFields(): array
    {
        return [];
    }
}
