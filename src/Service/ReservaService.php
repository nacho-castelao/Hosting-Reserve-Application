<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Repository\ReservaEventoRepositoryInterface;
use App\Repository\ReservaRepositoryInterface;
use App\Repository\TransactionManager;
use App\Validation\ReservaValidator;

/**
 * Reglas de negocio de las reservas. Sin SQL: delega en los repositorios y
 * garantiza que cada cambio y su evento de historial van en la misma transacción.
 */
final class ReservaService
{
    public const EVENTO_CREACION = 'creacion';
    public const EVENTO_CAMBIO_ESTADO = 'cambio_estado';

    public function __construct(
        private readonly ReservaRepositoryInterface $reservas,
        private readonly ReservaEventoRepositoryInterface $eventos,
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
    public function listar(FiltroReservas $filtro): array
    {
        return [
            'items' => $this->reservas->search($filtro),
            'total' => $this->reservas->count($filtro),
        ];
    }

    /**
     * Resumen global para el panel: reservas por estado y facturación prevista
     * (suma de importes de las reservas no canceladas).
     *
     * @return array{total: int, pendiente: int, confirmada: int, cancelada: int, ingresos: string}
     */
    public function resumen(): array
    {
        $porEstado = $this->reservas->totalesPorEstado();

        $resumen = ['total' => 0];
        $ingresos = 0.0;
        foreach (EstadoReserva::cases() as $estado) {
            $fila = $porEstado[$estado->value] ?? ['total' => 0, 'importe' => '0'];
            $resumen[$estado->value] = $fila['total'];
            $resumen['total'] += $fila['total'];
            if ($estado !== EstadoReserva::Cancelada) {
                $ingresos += (float) $fila['importe'];
            }
        }
        $resumen['ingresos'] = number_format($ingresos, 2, '.', '');

        return $resumen;
    }

    /**
     * Detalle de la reserva con su historial en 'eventos'.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function obtener(int $id): array
    {
        $reserva = $this->reservas->findById($id);
        if ($reserva === null) {
            throw new NotFoundException('La reserva solicitada no existe.');
        }
        $reserva['eventos'] = $this->eventos->findByReserva($id);

        return $reserva;
    }

    /**
     * @param array<string, mixed> $input Cuerpo JSON sin validar.
     * @return array<string, mixed> La reserva creada (con su historial).
     */
    public function crear(array $input): array
    {
        $nueva = $this->validator->validateCreate($input);

        $id = $this->transactions->transactional(function () use ($nueva): int {
            $id = $this->reservas->insert($nueva);
            $this->eventos->insert(
                $id,
                self::EVENTO_CREACION,
                'Reserva creada con estado ' . EstadoReserva::Pendiente->value . '.',
            );

            return $id;
        });

        return $this->obtener($id);
    }

    /**
     * @param array<string, mixed> $input Cuerpo JSON sin validar.
     * @return array<string, mixed> La reserva actualizada (con su historial).
     * @throws NotFoundException|ConflictException
     */
    public function cambiarEstado(int $id, array $input): array
    {
        $nuevo = $this->validator->validateEstado($input);

        $this->transactions->transactional(function () use ($id, $nuevo): void {
            // La fila queda bloqueada hasta el commit: dos peticiones simultáneas no
            // pueden validar la transición sobre el mismo estado de partida.
            $reserva = $this->reservas->findByIdForUpdate($id);
            if ($reserva === null) {
                throw new NotFoundException('La reserva solicitada no existe.');
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
            );
        });

        return $this->obtener($id);
    }
}
