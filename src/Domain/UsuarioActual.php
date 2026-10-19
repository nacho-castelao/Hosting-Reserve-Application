<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Usuario autenticado de la petición en curso.
 */
final class UsuarioActual
{
    public function __construct(
        public readonly int $id,
        public readonly string $nombre,
        public readonly string $email,
        public readonly Rol $rol,
    ) {
    }

    public function esAdmin(): bool
    {
        return $this->rol === Rol::Admin;
    }

    /** Id de anfitrión por el que acotar las consultas; null = sin acotar (admin). */
    public function ambitoAnfitrion(): ?int
    {
        return $this->esAdmin() ? null : $this->id;
    }

    /** @return array{id: int, nombre: string, email: string, rol: string, rol_label: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'email' => $this->email,
            'rol' => $this->rol->value,
            'rol_label' => $this->rol->label(),
        ];
    }
}
