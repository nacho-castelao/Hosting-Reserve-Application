<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Roles de los usuarios del panel. Los huéspedes no tienen cuenta.
 * Solo se puede registrar un anfitrión desde la web; los administradores se
 * crean con bin/crear-admin.php.
 */
enum Rol: string
{
    case Anfitrion = 'anfitrion';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Anfitrion => 'Anfitrión',
            self::Admin => 'Administrador',
        };
    }
}
