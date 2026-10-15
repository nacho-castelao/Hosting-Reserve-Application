<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Arranque perezoso de la sesión con flags seguros en la cookie.
 */
final class Session
{
    private static string $cookiePath = '/';

    public static function configure(string $basePath): void
    {
        self::$cookiePath = $basePath === '' ? '/' : $basePath . '/';
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');   // rechaza IDs de sesión no emitidos por el servidor
        ini_set('session.use_only_cookies', '1');  // el ID nunca viaja en la URL
        ini_set('session.use_trans_sid', '0');

        session_name('RESERVAS_SID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::$cookiePath,
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            // Hostings con proxy inverso delante. Solo afecta al flag Secure de la cookie.
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
