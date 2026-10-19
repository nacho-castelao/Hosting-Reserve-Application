<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sesión autenticada: guarda solo el id del usuario. El rol y el nombre se
 * leen de la base de datos en cada petición, así que un cambio de rol o un
 * usuario borrado tienen efecto inmediato.
 */
final class Auth
{
    private const USER_KEY = '_uid';
    private const SEEN_KEY = '_seen';

    /** Sin actividad durante este tiempo (segundos), la sesión caduca. */
    public const IDLE_TIMEOUT = 7200;

    public static function login(int $userId): void
    {
        // ID de sesión nuevo (evita la fijación de sesión) y token CSRF nuevo.
        Session::regenerate();
        $_SESSION[self::USER_KEY] = $userId;
        $_SESSION[self::SEEN_KEY] = time();
        Csrf::rotate();
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    /** Id del usuario autenticado, o null si no hay sesión o ha caducado por inactividad. */
    public static function userId(): ?int
    {
        // Sin cookie de sesión no hay usuario: no se abre una sesión vacía para nada.
        if (!Session::isActive() && !Session::hasCookie()) {
            return null;
        }
        Session::start();

        $id = $_SESSION[self::USER_KEY] ?? null;
        if (!is_int($id)) {
            return null;
        }

        $seen = $_SESSION[self::SEEN_KEY] ?? 0;
        if (!is_int($seen) || time() - $seen > self::IDLE_TIMEOUT) {
            self::logout();

            return null;
        }
        $_SESSION[self::SEEN_KEY] = time();

        return $id;
    }
}
