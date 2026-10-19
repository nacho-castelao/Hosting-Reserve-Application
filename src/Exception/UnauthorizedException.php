<?php

declare(strict_types=1);

namespace App\Exception;

/** Hace falta iniciar sesión (HTTP 401). */
final class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Necesitas iniciar sesión.')
    {
        parent::__construct(401, $message);
    }
}
