<?php

declare(strict_types=1);

namespace App\Exception;

final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowed Métodos admitidos por la ruta (cabecera Allow).
     */
    public function __construct(array $allowed)
    {
        parent::__construct(405, 'Método no permitido.', ['Allow' => implode(', ', $allowed)]);
    }
}
