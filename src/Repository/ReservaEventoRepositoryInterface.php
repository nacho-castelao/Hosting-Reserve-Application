<?php

declare(strict_types=1);

namespace App\Repository;

interface ReservaEventoRepositoryInterface
{
    /** @param int|null $usuarioId Quién hizo el cambio (null = el huésped desde la web pública). */
    public function insert(int $reservaId, string $tipo, string $descripcion, ?int $usuarioId): void;

    /** @return list<array<string, mixed>> Eventos en orden cronológico, con 'usuario' (nombre o null). */
    public function findByReserva(int $reservaId): array;
}
