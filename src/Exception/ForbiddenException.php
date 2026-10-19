<?php

declare(strict_types=1);

namespace App\Exception;

/** Sesión válida, pero sin permiso para esta acción (HTTP 403). */
final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'No tienes permiso para realizar esta acción.')
    {
        parent::__construct(403, $message);
    }
}
