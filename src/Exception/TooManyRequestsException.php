<?php

declare(strict_types=1);

namespace App\Exception;

/** Límite de peticiones superado (HTTP 429), con Retry-After en segundos. */
final class TooManyRequestsException extends HttpException
{
    public function __construct(private readonly int $retryAfter)
    {
        $minutos = max(1, (int) ceil($retryAfter / 60));
        parent::__construct(
            429,
            "Demasiados intentos. Vuelve a intentarlo dentro de {$minutos} " . ($minutos === 1 ? 'minuto.' : 'minutos.'),
            ['Retry-After' => (string) $retryAfter],
        );
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
