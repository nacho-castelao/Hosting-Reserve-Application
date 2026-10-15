<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Domain\NuevaReserva;
use App\Repository\ReservaRepositoryInterface;

final class InMemoryReservaRepository implements ReservaRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;

    public function search(FiltroReservas $filtro): array
    {
        return array_values($this->rows);
    }

    public function count(FiltroReservas $filtro): int
    {
        return count($this->rows);
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        return $this->findById($id);
    }

    public function insert(NuevaReserva $reserva): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'nombre_huesped' => $reserva->nombreHuesped,
            'email_huesped' => $reserva->emailHuesped,
            'nombre_alojamiento' => $reserva->nombreAlojamiento,
            'fecha_entrada' => $reserva->fechaEntrada->format('Y-m-d'),
            'fecha_salida' => $reserva->fechaSalida->format('Y-m-d'),
            'estado' => EstadoReserva::Pendiente->value,
            'importe' => $reserva->importe,
            'notas' => $reserva->notas,
            'fecha_creacion' => '2026-10-15 12:00:00',
        ];

        return $id;
    }

    public function updateEstado(int $id, EstadoReserva $estado): void
    {
        $this->rows[$id]['estado'] = $estado->value;
    }
}
