<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ReservaEventoRepository implements ReservaEventoRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(int $reservaId, string $tipo, string $descripcion, ?int $usuarioId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reserva_evento (reserva_id, usuario_id, tipo, descripcion)
             VALUES (:reserva_id, :usuario_id, :tipo, :descripcion)'
        );
        $stmt->execute([
            ':reserva_id' => $reservaId,
            ':usuario_id' => $usuarioId,
            ':tipo' => $tipo,
            ':descripcion' => $descripcion,
        ]);
    }

    public function findByReserva(int $reservaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.tipo, e.descripcion, e.fecha_creacion, u.nombre AS usuario
             FROM reserva_evento e LEFT JOIN usuario u ON u.id = e.usuario_id
             WHERE e.reserva_id = :reserva_id ORDER BY e.fecha_creacion ASC, e.id ASC'
        );
        $stmt->execute([':reserva_id' => $reservaId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'tipo' => (string) $row['tipo'],
            'descripcion' => (string) $row['descripcion'],
            'usuario' => $row['usuario'] === null ? null : (string) $row['usuario'],
            'fecha_creacion' => (string) $row['fecha_creacion'],
        ], $stmt->fetchAll());
    }
}
