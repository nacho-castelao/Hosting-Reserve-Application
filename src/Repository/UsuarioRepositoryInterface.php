<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Rol;

/**
 * Filas normalizadas: id int, rol string. password_hash solo sale de findByEmail,
 * que es lo que necesita el inicio de sesión.
 */
interface UsuarioRepositoryInterface
{
    /** @return array{id: int, nombre: string, email: string, rol: string}|null */
    public function findById(int $id): ?array;

    /** @return array{id: int, nombre: string, email: string, rol: string, password_hash: string}|null */
    public function findByEmail(string $email): ?array;

    /**
     * @throws DuplicateEmailException si el email ya está registrado.
     */
    public function insert(string $nombre, string $email, string $passwordHash, Rol $rol): int;

    public function updatePassword(int $id, string $passwordHash): void;

    public function updateRol(int $id, Rol $rol): void;

    /**
     * Anfitriones con su número de alojamientos y reservas (panel del administrador).
     *
     * @return list<array<string, mixed>>
     */
    public function listarAnfitriones(): array;
}
