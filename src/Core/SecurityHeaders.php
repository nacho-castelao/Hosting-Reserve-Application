<?php

declare(strict_types=1);

namespace App\Core;

final class SecurityHeaders
{
    /**
     * CSP estricta: solo recursos propios y ningún script inline (lo que además
     * impide manejadores onclick="..." en las plantillas).
     */
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('Content-Security-Policy: ' . self::CSP);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // Las páginas incluyen el token CSRF y datos personales: que no se guarden en cachés.
        header('Cache-Control: no-store, private');

        if (Session::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
