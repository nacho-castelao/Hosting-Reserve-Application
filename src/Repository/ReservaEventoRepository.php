<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ReservaEventoRepository implements ReservaEventoRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(int $reservaId, string $tipo, string $descripcion): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reserva_evento (reserva_id, tipo, descripcion) VALUES (:reserva_id, :tipo, :descripcion)'
        );
        $stmt->execute([':reserva_id' => $reservaId, ':tipo' => $tipo, ':descripcion' => $descripcion]);
    }

    public function findByReserva(int $reservaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tipo, descripcion, fecha_creacion FROM reserva_evento
             WHERE reserva_id = :reserva_id ORDER BY fecha_creacion ASC, id ASC'
        );
        $stmt->execute([':reserva_id' => $reservaId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'tipo' => (string) $row['tipo'],
            'descripcion' => (string) $row['descripcion'],
            'fecha_creacion' => (string) $row['fecha_creacion'],
        ], $stmt->fetchAll());
    }
}
