<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\ReservaEventoRepositoryInterface;

final class InMemoryReservaEventoRepository implements ReservaEventoRepositoryInterface
{
    /** @var list<array{reserva_id: int, tipo: string, descripcion: string}> */
    public array $eventos = [];

    public function insert(int $reservaId, string $tipo, string $descripcion): void
    {
        $this->eventos[] = ['reserva_id' => $reservaId, 'tipo' => $tipo, 'descripcion' => $descripcion];
    }

    public function findByReserva(int $reservaId): array
    {
        return array_values(array_filter($this->eventos, static fn (array $e): bool => $e['reserva_id'] === $reservaId));
    }
}
