<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Datos validados para registrar un usuario. La contraseña llega en claro y
 * el servicio la convierte en hash antes de guardarla.
 */
final class NuevoUsuario
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $email,
        #[\SensitiveParameter]
        public readonly string $password,
    ) {
    }
}
