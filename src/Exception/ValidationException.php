<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Datos de entrada sintácticamente correctos pero semánticamente inválidos (HTTP 422).
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string, string> $fields Mensaje de error por cada campo inválido.
     */
    public function __construct(
        private readonly array $fields,
        string $message = 'Los datos enviados no son válidos.',
    ) {
        parent::__construct(422, $message);
    }

    public function getFields(): array
    {
        return $this->fields;
    }
}
