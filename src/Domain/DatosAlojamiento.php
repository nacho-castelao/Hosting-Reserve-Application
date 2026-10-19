<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Datos validados de un alojamiento (alta y edición). El anfitrión no viene
 * del cliente: es el usuario autenticado.
 */
final class DatosAlojamiento
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $ciudad,
        /** Precio por noche como cadena decimal ("89.90"). */
        public readonly string $precioNoche,
        public readonly bool $activo,
    ) {
    }
}
