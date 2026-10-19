<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\DatosAlojamiento;
use PDO;

final class AlojamientoRepository implements AlojamientoRepositoryInterface
{
    private const COLUMNS = 'a.id, a.anfitrion_id, a.nombre, a.ciudad, a.precio_noche, a.activo, a.fecha_creacion';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listarActivos(): array
    {
        $stmt = $this->pdo->query(
            'SELECT a.id, a.nombre, a.ciudad, a.precio_noche FROM alojamiento a
             WHERE a.activo = 1 ORDER BY a.ciudad, a.nombre'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'ciudad' => (string) $row['ciudad'],
            'precio_noche' => (string) $row['precio_noche'],
        ], $stmt->fetchAll());
    }

    public function listar(?int $anfitrionId): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ', u.nombre AS anfitrion, COUNT(r.id) AS reservas
                FROM alojamiento a
                JOIN usuario u ON u.id = a.anfitrion_id
                LEFT JOIN reserva r ON r.alojamiento_id = a.id';
        $params = [];
        if ($anfitrionId !== null) {
            $sql .= ' WHERE a.anfitrion_id = :anfitrion';
            $params[':anfitrion'] = $anfitrionId;
        }
        $stmt = $this->pdo->prepare($sql . ' GROUP BY a.id ORDER BY a.activo DESC, a.ciudad, a.nombre');
        $stmt->execute($params);

        return array_map(fn (array $row): array => $this->hydrate($row) + [
            'anfitrion' => (string) $row['anfitrion'],
            'reservas' => (int) $row['reservas'],
        ], $stmt->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM alojamiento a WHERE a.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM alojamiento a WHERE a.id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(int $anfitrionId, DatosAlojamiento $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO alojamiento (anfitrion_id, nombre, ciudad, precio_noche, activo)
             VALUES (:anfitrion_id, :nombre, :ciudad, :precio_noche, :activo)'
        );
        $stmt->execute([
            ':anfitrion_id' => $anfitrionId,
            ':nombre' => $datos->nombre,
            ':ciudad' => $datos->ciudad,
            ':precio_noche' => $datos->precioNoche,
            ':activo' => (int) $datos->activo,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, DatosAlojamiento $datos): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE alojamiento SET nombre = :nombre, ciudad = :ciudad, precio_noche = :precio_noche, activo = :activo
             WHERE id = :id'
        );
        $stmt->execute([
            ':nombre' => $datos->nombre,
            ':ciudad' => $datos->ciudad,
            ':precio_noche' => $datos->precioNoche,
            ':activo' => (int) $datos->activo,
            ':id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'anfitrion_id' => (int) $row['anfitrion_id'],
            'nombre' => (string) $row['nombre'],
            'ciudad' => (string) $row['ciudad'],
            'precio_noche' => (string) $row['precio_noche'],
            'activo' => (bool) $row['activo'],
            'fecha_creacion' => (string) $row['fecha_creacion'],
        ];
    }
}
