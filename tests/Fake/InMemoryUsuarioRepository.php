<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\Rol;
use App\Repository\DuplicateEmailException;
use App\Repository\UsuarioRepositoryInterface;

final class InMemoryUsuarioRepository implements UsuarioRepositoryInterface
{
    /** @var array<int, array{id: int, nombre: string, email: string, rol: string, password_hash: string}> */
    public array $rows = [];
    private int $nextId = 1;

    public function findById(int $id): ?array
    {
        if (!isset($this->rows[$id])) {
            return null;
        }
        $row = $this->rows[$id];
        unset($row['password_hash']);

        return $row;
    }

    public function findByEmail(string $email): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['email'] === $email) {
                return $row;
            }
        }

        return null;
    }

    public function insert(string $nombre, string $email, string $passwordHash, Rol $rol): int
    {
        if ($this->findByEmail($email) !== null) {
            throw new DuplicateEmailException();
        }
        $id = $this->nextId++;
        $this->rows[$id] = ['id' => $id, 'nombre' => $nombre, 'email' => $email, 'rol' => $rol->value, 'password_hash' => $passwordHash];

        return $id;
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->rows[$id]['password_hash'] = $passwordHash;
    }

    public function updateRol(int $id, Rol $rol): void
    {
        $this->rows[$id]['rol'] = $rol->value;
    }

    public function listarAnfitriones(): array
    {
        return array_values(array_filter($this->rows, static fn (array $u): bool => $u['rol'] === Rol::Anfitrion->value));
    }
}
