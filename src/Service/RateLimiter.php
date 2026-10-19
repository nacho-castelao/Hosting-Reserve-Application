<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\TooManyRequestsException;
use App\Repository\LimitePeticionRepositoryInterface;
use InvalidArgumentException;

/**
 * Rate limiting de ventana fija guardado en la base de datos (funciona igual
 * con varios procesos PHP-FPM y sobrevive a reinicios). Las claves se guardan
 * como hash: la tabla no contiene IPs ni emails en claro.
 */
final class RateLimiter
{
    public const LOGIN_IP = 'login_ip';
    public const LOGIN_EMAIL = 'login_email';
    public const REGISTRO_IP = 'registro_ip';
    public const RESERVA_IP = 'reserva_ip';
    public const ESCRITURA_USUARIO = 'escritura_usuario';

    /** Intentos permitidos y duración de la ventana en segundos. */
    public const LIMITES = [
        self::LOGIN_IP => ['max' => 10, 'ventana' => 900],
        self::LOGIN_EMAIL => ['max' => 5, 'ventana' => 900],
        self::REGISTRO_IP => ['max' => 5, 'ventana' => 3600],
        self::RESERVA_IP => ['max' => 10, 'ventana' => 3600],
        self::ESCRITURA_USUARIO => ['max' => 60, 'ventana' => 60],
    ];

    public function __construct(private readonly LimitePeticionRepositoryInterface $repository)
    {
    }

    /**
     * Cuenta un intento de $sujeto (IP, email o id de usuario) en el cubo indicado.
     *
     * @throws TooManyRequestsException si supera el máximo de la ventana.
     */
    public function consumir(string $cubo, string $sujeto): void
    {
        $limite = self::LIMITES[$cubo] ?? throw new InvalidArgumentException("Cubo de rate limiting desconocido: {$cubo}");

        $estado = $this->repository->registrar(self::clave($cubo, $sujeto), $limite['ventana']);
        if ($estado['intentos'] > $limite['max']) {
            throw new TooManyRequestsException($estado['restante']);
        }
    }

    /** Pone el contador a cero (p. ej. el del email tras un inicio de sesión correcto). */
    public function limpiar(string $cubo, string $sujeto): void
    {
        $this->repository->limpiar(self::clave($cubo, $sujeto));
    }

    private static function clave(string $cubo, string $sujeto): string
    {
        return hash('sha256', $cubo . "\0" . $sujeto);
    }
}
