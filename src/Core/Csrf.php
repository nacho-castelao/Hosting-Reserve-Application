<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\HttpException;

/**
 * Token anti-CSRF ligado a la sesión. Se publica en <meta name="csrf-token">
 * y el JS lo devuelve en la cabecera X-CSRF-Token en cada petición de escritura.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        Session::start();
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * @throws HttpException 403 si el token falta o no coincide.
     */
    public static function verify(?string $token): void
    {
        Session::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        // hash_equals compara en tiempo constante (evita ataques de temporización).
        if (!is_string($expected) || $token === null || !hash_equals($expected, $token)) {
            throw new HttpException(403, 'Token CSRF ausente o no válido. Recarga la página e inténtalo de nuevo.');
        }
    }
}
