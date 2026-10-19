<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Domain\Importe;
use App\Domain\UsuarioActual;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Repository\AlojamientoRepositoryInterface;
use App\Repository\ReservaEventoRepositoryInterface;
use App\Repository\ReservaRepositoryInterface;
use App\Repository\TransactionManager;
use App\Validation\ReservaValidator;
use InvalidArgumentException;

/**
 * Reglas de negocio de las reservas. Sin SQL: delega en los repositorios y
 * garantiza que cada cambio y su evento de historial van en la misma transacción.
 *
 * Autorización: un anfitrión solo ve y gestiona las reservas de sus alojamientos;
 * las de otros responden 404 (no 403), para no revelar qué ids existen.
 */
final class ReservaService
{
    public const EVENTO_CREACION = 'creacion';
    public const EVENTO_CAMBIO_ESTADO = 'cambio_estado';

    private const NO_EXISTE = 'La reserva solicitada no existe.';

    public function __construct(
        private readonly ReservaRepositoryInterface $reservas,
        private readonly ReservaEventoRepositoryInterface $eventos,
        private readonly AlojamientoRepositoryInterface $alojamientos,
        private readonly TransactionManager $transactions,
        private readonly ReservaValidator $validator,
    ) {
    }

    /**
     * @param array<string, mixed> $query Query string sin validar.
     */
    public function parseFiltros(array $query): FiltroReservas
    {
        return $this->validator->validateFiltros($query);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listar(FiltroReservas $filtro, UsuarioActual $usuario): array
    {
        $filtro = $filtro->conAnfitrion($usuario->ambitoAnfitrion());

        return [
            'items' => $this->reservas->search($filtro),
            'total' => $this->reservas->count($filtro),
        ];
    }

    /**
     * Resumen para el panel: reservas por estado y facturación prevista (suma de
     * importes de las reservas no canceladas), acotado al ámbito del usuario.
     *
     * @return array{total: int, pendiente: int, confirmada: int, cancelada: int, ingresos: string}
     */
    public function resumen(UsuarioActual $usuario): array
    {
        $porEstado = $this->reservas->totalesPorEstado($usuario->ambitoAnfitrion());

        $resumen = ['total' => 0];
        $ingresos = 0;
        foreach (EstadoReserva::cases() as $estado) {
            $fila = $porEstado[$estado->value] ?? ['total' => 0, 'importe' => '0.00'];
            $resumen[$estado->value] = $fila['total'];
            $resumen['total'] += $fila['total'];
            if ($estado !== EstadoReserva::Cancelada) {
                $ingresos += Importe::aCentimos($fila['importe']);
            }
        }
        $resumen['ingresos'] = Importe::desdeCentimos($ingresos);

        return $resumen;
    }

    /**
     * Detalle de la reserva con su historial en 'eventos'.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function obtener(int $id, UsuarioActual $usuario): array
    {
        $reserva = $this->reservas->findById($id);
        if ($reserva === null || !self::puedeVer($reserva, $usuario)) {
            throw new NotFoundException(self::NO_EXISTE);
        }
        $reserva['eventos'] = $this->eventos->findByReserva($id);

        return $reserva;
    }

    /**
     * Alta pública (huésped sin cuenta). El importe lo calcula el servidor:
     * noches × precio por noche del alojamiento en ese momento.
     *
     * @param array<string, mixed> $input Cuerpo JSON sin validar.
     * @return array<string, mixed> Resumen de la reserva creada, apto para el huésped.
     * @throws ValidationException|NotFoundException|ConflictException
     */
    public function crear(array $input): array
    {
        $nueva = $this->validator->validateCreate($input);

        $id = $this->transactions->transactional(function () use ($nueva): int {
            // Bloquear el alojamiento serializa las altas de ese alojamiento: dos huéspedes
            // no pueden pasar a la vez la comprobación de solapamiento con las mismas fechas.
            $alojamiento = $this->alojamientos->findByIdForUpdate($nueva->alojamientoId);
            if ($alojamiento === null || !$alojamiento['activo']) {
                throw new NotFoundException('El alojamiento no existe o no admite reservas.');
            }

            if ($this->reservas->haySolapamiento($nueva->alojamientoId, $nueva->fechaEntrada, $nueva->fechaSalida)) {
                throw new ConflictException('El alojamiento ya está reservado en alguna de esas noches. Prueba con otras fechas.');
            }

            try {
                $importe = Importe::porNoches($alojamiento['precio_noche'], $nueva->noches());
            } catch (InvalidArgumentException) {
                throw new ValidationException(['fecha_salida' => 'La estancia es demasiado larga.']);
            }

            $id = $this->reservas->insert($nueva, $importe);
            $this->eventos->insert(
                $id,
                self::EVENTO_CREACION,
                'Reserva creada con estado ' . EstadoReserva::Pendiente->value . '.',
                null,
            );

            return $id;
        });

        /** @var array<string, mixed> $reserva */
        $reserva = $this->reservas->findById($id);

        return self::paraHuesped($reserva);
    }

    /**
     * @param array<string, mixed> $input Cuerpo JSON sin validar.
     * @return array<string, mixed> La reserva actualizada (con su historial).
     * @throws NotFoundException|ConflictException
     */
    public function cambiarEstado(int $id, array $input, UsuarioActual $usuario): array
    {
        $nuevo = $this->validator->validateEstado($input);

        $this->transactions->transactional(function () use ($id, $nuevo, $usuario): void {
            // La fila queda bloqueada hasta el commit: dos peticiones simultáneas no
            // pueden validar la transición sobre el mismo estado de partida.
            $reserva = $this->reservas->findByIdForUpdate($id);
            if ($reserva === null || !self::puedeVer($reserva, $usuario)) {
                throw new NotFoundException(self::NO_EXISTE);
            }

            $actual = EstadoReserva::from($reserva['estado']);
            if ($actual === $nuevo) {
                throw new ConflictException("La reserva ya está {$actual->value}.");
            }
            if (!$actual->canTransitionTo($nuevo)) {
                throw new ConflictException("No se puede pasar una reserva de {$actual->value} a {$nuevo->value}.");
            }

            $this->reservas->updateEstado($id, $nuevo);
            $this->eventos->insert(
                $id,
                self::EVENTO_CAMBIO_ESTADO,
                "Estado cambiado de {$actual->value} a {$nuevo->value}.",
                $usuario->id,
            );
        });

        return $this->obtener($id, $usuario);
    }

    /** @param array<string, mixed> $reserva */
    private static function puedeVer(array $reserva, UsuarioActual $usuario): bool
    {
        return $usuario->esAdmin() || $reserva['anfitrion_id'] === $usuario->id;
    }

    /**
     * Lo que se le devuelve al huésped: sus propios datos y el importe calculado,
     * sin ids internos del anfitrión ni historial.
     *
     * @param array<string, mixed> $reserva
     * @return array<string, mixed>
     */
    private static function paraHuesped(array $reserva): array
    {
        return array_intersect_key($reserva, array_flip([
            'id', 'alojamiento_id', 'nombre_alojamiento', 'nombre_huesped', 'email_huesped',
            'fecha_entrada', 'fecha_salida', 'estado', 'importe', 'notas',
        ]));
    }
}
