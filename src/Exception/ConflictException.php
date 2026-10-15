<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * La petición es válida pero choca con el estado actual del recurso (HTTP 409),
 * p. ej. una transición de estado no permitida.
 */
final class ConflictException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(409, $message);
    }
}
