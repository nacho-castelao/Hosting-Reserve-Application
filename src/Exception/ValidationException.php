<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Errores de validación con el detalle por campo.
 * 422 para el cuerpo de una escritura; 400 para filtros de la query string.
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string, string> $fields Mensaje de error por cada campo inválido.
     */
    public function __construct(
        private readonly array $fields,
        string $message = 'Los datos enviados no son válidos.',
        int $statusCode = 422,
    ) {
        parent::__construct($statusCode, $message);
    }

    public function getFields(): array
    {
        return $this->fields;
    }
}
