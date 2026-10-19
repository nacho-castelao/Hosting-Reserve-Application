<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\DatosAlojamiento;
use App\Repository\AlojamientoRepositoryInterface;

final class InMemoryAlojamientoRepository implements AlojamientoRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;

    /** Atajo para preparar los tests. */
    public function add(int $anfitrionId, string $precioNoche, bool $activo = true, string $nombre = 'Apartamento Ruzafa'): int
    {
        return $this->insert($anfitrionId, new DatosAlojamiento($nombre, 'Valencia', $precioNoche, $activo));
    }

    public function listarActivos(): array
    {
        return array_values(array_map(
            static fn (array $a): array => array_intersect_key($a, array_flip(['id', 'nombre', 'ciudad', 'precio_noche'])),
            array_filter($this->rows, static fn (array $a): bool => $a['activo']),
        ));
    }

    public function listar(?int $anfitrionId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $a): bool => $anfitrionId === null || $a['anfitrion_id'] === $anfitrionId,
        ));
    }

    public function findById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        return $this->findById($id);
    }

    public function insert(int $anfitrionId, DatosAlojamiento $datos): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id' => $id,
            'anfitrion_id' => $anfitrionId,
            'nombre' => $datos->nombre,
            'ciudad' => $datos->ciudad,
            'precio_noche' => $datos->precioNoche,
            'activo' => $datos->activo,
            'fecha_creacion' => '2026-10-15 12:00:00',
        ];

        return $id;
    }

    public function update(int $id, DatosAlojamiento $datos): void
    {
        $this->rows[$id] = [
            'nombre' => $datos->nombre,
            'ciudad' => $datos->ciudad,
            'precio_noche' => $datos->precioNoche,
            'activo' => $datos->activo,
        ] + $this->rows[$id];
    }
}
