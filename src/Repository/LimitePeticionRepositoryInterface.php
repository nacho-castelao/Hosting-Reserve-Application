<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Contadores de ventana fija para el rate limiting.
 */
interface LimitePeticionRepositoryInterface
{
    /**
     * Suma un intento a la clave. Si su ventana ha caducado, empieza una nueva.
     *
     * @return array{intentos: int, restante: int} Intentos en la ventana actual y segundos hasta que caduque.
     */
    public function registrar(string $clave, int $ventanaSegundos): array;

    public function limpiar(string $clave): void;
}
