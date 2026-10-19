<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\DatosAlojamiento;

/**
 * Filas normalizadas: id/anfitrion_id int, precio_noche string decimal, activo bool.
 */
interface AlojamientoRepositoryInterface
{
    /**
     * Alojamientos activos para el catálogo público (sin datos del anfitrión).
     *
     * @return list<array<string, mixed>>
     */
    public function listarActivos(): array;

    /**
     * Alojamientos del panel con el nombre del anfitrión y su número de reservas.
     * Con $anfitrionId solo los suyos; null = todos (administrador).
     *
     * @return list<array<string, mixed>>
     */
    public function listar(?int $anfitrionId): array;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /**
     * Igual que findById pero bloquea la fila hasta el fin de la transacción:
     * serializa las altas de reserva de un mismo alojamiento.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdForUpdate(int $id): ?array;

    public function insert(int $anfitrionId, DatosAlojamiento $datos): int;

    public function update(int $id, DatosAlojamiento $datos): void;
}
