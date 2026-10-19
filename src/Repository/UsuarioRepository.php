<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Rol;
use PDO;
use PDOException;

final class UsuarioRepository implements UsuarioRepositoryInterface
{
    /** Código de MySQL/MariaDB para una clave única duplicada. */
    private const ER_DUP_ENTRY = 1062;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nombre, email, rol FROM usuario WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'email' => (string) $row['email'],
            'rol' => (string) $row['rol'],
        ];
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nombre, email, rol, password_hash FROM usuario WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'email' => (string) $row['email'],
            'rol' => (string) $row['rol'],
            'password_hash' => (string) $row['password_hash'],
        ];
    }

    public function insert(string $nombre, string $email, string $passwordHash, Rol $rol): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuario (nombre, email, password_hash, rol) VALUES (:nombre, :email, :hash, :rol)'
        );
        try {
            $stmt->execute([':nombre' => $nombre, ':email' => $email, ':hash' => $passwordHash, ':rol' => $rol->value]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::ER_DUP_ENTRY) {
                throw new DuplicateEmailException('El email ya está registrado.', 0, $e);
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuario SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $passwordHash, ':id' => $id]);
    }

    public function updateRol(int $id, Rol $rol): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuario SET rol = :rol WHERE id = :id');
        $stmt->execute([':rol' => $rol->value, ':id' => $id]);
    }

    public function listarAnfitriones(): array
    {
        // Subconsultas en lugar de dos JOIN: así las reservas no se multiplican por alojamiento.
        $stmt = $this->pdo->query(
            "SELECT u.id, u.nombre, u.email, u.fecha_creacion,
                    (SELECT COUNT(*) FROM alojamiento a WHERE a.anfitrion_id = u.id) AS alojamientos,
                    (SELECT COUNT(*) FROM reserva r JOIN alojamiento a ON a.id = r.alojamiento_id
                     WHERE a.anfitrion_id = u.id) AS reservas
             FROM usuario u WHERE u.rol = 'anfitrion' ORDER BY u.nombre"
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'email' => (string) $row['email'],
            'fecha_creacion' => (string) $row['fecha_creacion'],
            'alojamientos' => (int) $row['alojamientos'],
            'reservas' => (int) $row['reservas'],
        ], $stmt->fetchAll());
    }
}
