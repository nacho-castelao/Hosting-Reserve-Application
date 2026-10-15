<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Datos ya validados y normalizados para crear una reserva.
 * Solo contiene los campos que el cliente puede fijar: id, estado y
 * fecha_creacion los decide el servidor (sin asignación masiva).
 */
final class NuevaReserva
{
    public function __construct(
        public readonly string $nombreHuesped,
        public readonly string $emailHuesped,
        public readonly string $nombreAlojamiento,
        public readonly DateTimeImmutable $fechaEntrada,
        public readonly DateTimeImmutable $fechaSalida,
        /** Importe como cadena decimal ("120.50") para no perder precisión con floats. */
        public readonly string $importe,
        public readonly ?string $notas,
    ) {
    }
}
