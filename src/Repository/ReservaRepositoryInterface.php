<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Domain\NuevaReserva;

/**
 * Las filas se devuelven como arrays con tipos ya normalizados
 * (id int, importe string decimal, fechas 'Y-m-d' / 'Y-m-d H:i:s').
 */
interface ReservaRepositoryInterface
{
    /** @return list<array<string, mixed>> */
    public function search(FiltroReservas $filtro): array;

    public function count(FiltroReservas $filtro): int;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /**
     * Igual que findById pero bloquea la fila (SELECT … FOR UPDATE) hasta el
     * fin de la transacción. Debe llamarse dentro de una transacción.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdForUpdate(int $id): ?array;

    /** @return int id de la reserva creada */
    public function insert(NuevaReserva $reserva): int;

    public function updateEstado(int $id, EstadoReserva $estado): void;
}
