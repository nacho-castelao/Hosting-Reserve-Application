<?php

declare(strict_types=1);

namespace App\Repository;

interface ReservaEventoRepositoryInterface
{
    public function insert(int $reservaId, string $tipo, string $descripcion): void;

    /** @return list<array<string, mixed>> Eventos en orden cronológico. */
    public function findByReserva(int $reservaId): array;
}
