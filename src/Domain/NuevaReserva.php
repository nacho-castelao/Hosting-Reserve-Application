<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Datos ya validados y normalizados para crear una reserva.
 * Solo contiene los campos que el huésped puede fijar: id, estado, importe y
 * fecha_creacion los decide el servidor (sin asignación masiva).
 */
final class NuevaReserva
{
    public function __construct(
        public readonly int $alojamientoId,
        public readonly string $nombreHuesped,
        public readonly string $emailHuesped,
        public readonly DateTimeImmutable $fechaEntrada,
        public readonly DateTimeImmutable $fechaSalida,
        public readonly ?string $notas,
    ) {
    }

    /** Noches de la estancia: la salida no cuenta. */
    public function noches(): int
    {
        return (int) $this->fechaEntrada->diff($this->fechaSalida)->days;
    }
}
