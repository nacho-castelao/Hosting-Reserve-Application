<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Domain\NuevaReserva;
use App\Repository\ReservaRepositoryInterface;
use DateTimeImmutable;

/**
 * Solo implementa los filtros que necesitan los tests del servicio: el ámbito
 * del anfitrión (autorización) y el estado. El resto de filtros se prueban en SQL.
 */
final class InMemoryReservaRepository implements ReservaRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct(private readonly InMemoryAlojamientoRepository $alojamientos)
    {
    }

    public function search(FiltroReservas $filtro): array
    {
        return array_values(array_filter(
            array_map($this->withAlojamiento(...), $this->rows),
            static fn (array $r): bool => ($filtro->anfitrionId === null || $r['anfitrion_id'] === $filtro->anfitrionId)
                && ($filtro->estado === null || $r['estado'] === $filtro->estado->value),
        ));
    }

    public function count(FiltroReservas $filtro): int
    {
        return count($this->search($filtro));
    }

    public function totalesPorEstado(?int $anfitrionId): array
    {
        $totales = [];
        foreach ($this->search(new FiltroReservas(anfitrionId: $anfitrionId)) as $row) {
            $totales[$row['estado']] ??= ['total' => 0, 'importe' => '0.00'];
            $totales[$row['estado']]['total']++;
            $totales[$row['estado']]['importe'] = number_format(
                (float) $totales[$row['estado']]['importe'] + (float) $row['importe'],
                2,
                '.',
                '',
            );
        }

        return $totales;
    }

    public function findById(int $id): ?array
    {
        return isset($this->rows[$id]) ? $this->withAlojamiento($this->rows[$id]) : null;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        return $this->findById($id);
    }

    public function haySolapamiento(int $alojamientoId, DateTimeImmutable $entrada, DateTimeImmutable $salida): bool
    {
        foreach ($this->rows as $row) {
            if ($row['alojamiento_id'] === $alojamientoId
                && $row['estado'] !== EstadoReserva::Cancelada->value
                && $row['fecha_entrada'] < $salida->format('Y-m-d')
                && $row['fecha_salida'] > $entrada->format('Y-m-d')) {
                return true;
            }
        }

        return false;
    }

    public function insert(NuevaReserva $reserva, string $importe): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'alojamiento_id' => $reserva->alojamientoId,
            'nombre_huesped' => $reserva->nombreHuesped,
            'email_huesped' => $reserva->emailHuesped,
            'fecha_entrada' => $reserva->fechaEntrada->format('Y-m-d'),
            'fecha_salida' => $reserva->fechaSalida->format('Y-m-d'),
            'estado' => EstadoReserva::Pendiente->value,
            'importe' => $importe,
            'notas' => $reserva->notas,
            'fecha_creacion' => '2026-10-15 12:00:00',
        ];

        return $id;
    }

    public function updateEstado(int $id, EstadoReserva $estado): void
    {
        $this->rows[$id]['estado'] = $estado->value;
    }

    /**
     * Equivalente al JOIN con alojamiento del repositorio real.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withAlojamiento(array $row): array
    {
        $alojamiento = $this->alojamientos->findById($row['alojamiento_id']);

        return $row + [
            'nombre_alojamiento' => $alojamiento['nombre'] ?? '',
            'anfitrion_id' => $alojamiento['anfitrion_id'] ?? 0,
        ];
    }
}
