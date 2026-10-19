<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Arranque perezoso de la sesión con flags seguros en la cookie.
 */
final class Session
{
    private const NAME = 'RESERVAS_SID';
    private const FLASH_KEY = '_flash';

    private static string $cookiePath = '/';

    public static function configure(string $basePath): void
    {
        self::$cookiePath = $basePath === '' ? '/' : $basePath . '/';
    }

    public static function start(): void
    {
        if (self::isActive()) {
            return;
        }

        ini_set('session.use_strict_mode', '1');   // rechaza IDs de sesión no emitidos por el servidor
        ini_set('session.use_only_cookies', '1');  // el ID nunca viaja en la URL
        ini_set('session.use_trans_sid', '0');

        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::$cookiePath,
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /** ¿El navegador ha enviado una cookie de sesión? (sin abrir la sesión). */
    public static function hasCookie(): bool
    {
        return isset($_COOKIE[self::NAME]) && is_string($_COOKIE[self::NAME]);
    }

    /**
     * Nuevo ID de sesión conservando los datos. Se llama al cambiar de privilegios
     * (inicio de sesión) para evitar la fijación de sesión.
     */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    /** Vacía y destruye la sesión y caduca su cookie (cierre de sesión). */
    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (!headers_sent()) {
            setcookie(self::NAME, '', [
                'expires' => time() - 3600,
                'path' => self::$cookiePath,
                'secure' => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    /** Guarda un dato que solo sobrevive hasta la siguiente lectura (patrón PRG). */
    public static function flash(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[self::FLASH_KEY][$key] = $value;
    }

    public static function pullFlash(string $key): mixed
    {
        self::start();
        $value = $_SESSION[self::FLASH_KEY][$key] ?? null;
        unset($_SESSION[self::FLASH_KEY][$key]);

        return $value;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            // Hostings con proxy inverso delante. Solo afecta al flag Secure de la cookie.
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
