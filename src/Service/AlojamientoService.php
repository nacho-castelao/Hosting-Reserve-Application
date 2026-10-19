<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\UsuarioActual;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\AlojamientoRepositoryInterface;
use App\Validation\AlojamientoValidator;

/**
 * Catálogo público y gestión de alojamientos desde el panel. Un anfitrión solo
 * gestiona los suyos; los de otros responden 404 para no revelar qué ids existen.
 */
final class AlojamientoService
{
    private const NO_EXISTE = 'El alojamiento solicitado no existe.';

    public function __construct(
        private readonly AlojamientoRepositoryInterface $alojamientos,
        private readonly AlojamientoValidator $validator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function catalogo(): array
    {
        return $this->alojamientos->listarActivos();
    }

    /**
     * Ficha pública de un alojamiento activo (sin datos del anfitrión).
     *
     * @return array{id: int, nombre: string, ciudad: string, precio_noche: string}
     * @throws NotFoundException
     */
    public function publico(int $id): array
    {
        $alojamiento = $this->alojamientos->findById($id);
        if ($alojamiento === null || !$alojamiento['activo']) {
            throw new NotFoundException(self::NO_EXISTE);
        }

        return [
            'id' => $alojamiento['id'],
            'nombre' => $alojamiento['nombre'],
            'ciudad' => $alojamiento['ciudad'],
            'precio_noche' => $alojamiento['precio_noche'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listar(UsuarioActual $usuario): array
    {
        return $this->alojamientos->listar($usuario->ambitoAnfitrion());
    }

    /**
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function obtener(int $id, UsuarioActual $usuario): array
    {
        $alojamiento = $this->alojamientos->findById($id);
        if ($alojamiento === null || !($usuario->esAdmin() || $alojamiento['anfitrion_id'] === $usuario->id)) {
            throw new NotFoundException(self::NO_EXISTE);
        }

        return $alojamiento;
    }

    /**
     * El alojamiento pertenece siempre a quien lo crea.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function crear(array $input, UsuarioActual $usuario): int
    {
        return $this->alojamientos->insert($usuario->id, $this->validator->validate($input));
    }

    /**
     * Cambiar el precio no altera las reservas ya hechas: su importe se calculó al reservar.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException|NotFoundException
     */
    public function actualizar(int $id, array $input, UsuarioActual $usuario): void
    {
        $this->obtener($id, $usuario);
        $this->alojamientos->update($id, $this->validator->validate($input));
    }
}
