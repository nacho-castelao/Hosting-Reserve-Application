<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class LimitePeticionRepository implements LimitePeticionRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(string $clave, int $ventanaSegundos): array
    {
        // Un único upsert atómico: dos peticiones simultáneas no pueden leer el mismo contador.
        // MySQL/MariaDB evalúan las asignaciones de izquierda a derecha, así que "intentos" se
        // calcula con el "inicio" anterior y después se reinicia "inicio" si la ventana caducó.
        $stmt = $this->pdo->prepare(
            'INSERT INTO limite_peticion (clave, intentos, inicio) VALUES (:clave, 1, NOW())
             ON DUPLICATE KEY UPDATE
                intentos = IF(inicio <= NOW() - INTERVAL :v1 SECOND, 1, intentos + 1),
                inicio   = IF(inicio <= NOW() - INTERVAL :v2 SECOND, NOW(), inicio)'
        );
        $stmt->execute([':clave' => $clave, ':v1' => $ventanaSegundos, ':v2' => $ventanaSegundos]);

        $stmt = $this->pdo->prepare(
            'SELECT intentos, GREATEST(1, :v - TIMESTAMPDIFF(SECOND, inicio, NOW())) AS restante
             FROM limite_peticion WHERE clave = :clave'
        );
        $stmt->execute([':clave' => $clave, ':v' => $ventanaSegundos]);
        $row = $stmt->fetch();

        return ['intentos' => (int) $row['intentos'], 'restante' => (int) $row['restante']];
    }

    public function limpiar(string $clave): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM limite_peticion WHERE clave = :clave');
        $stmt->execute([':clave' => $clave]);
    }
}
