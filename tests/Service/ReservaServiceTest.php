<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeTransactionManager;
use Tests\Fake\InMemoryReservaEventoRepository;
use Tests\Fake\InMemoryReservaRepository;

final class ReservaServiceTest extends TestCase
{
    private InMemoryReservaRepository $reservas;
    private InMemoryReservaEventoRepository $eventos;
    private FakeTransactionManager $transactions;
    private ReservaService $service;

    protected function setUp(): void
    {
        $this->reservas = new InMemoryReservaRepository();
        $this->eventos = new InMemoryReservaEventoRepository();
        $this->transactions = new FakeTransactionManager();
        $this->service = new ReservaService(
            $this->reservas,
            $this->eventos,
            $this->transactions,
            new ReservaValidator(new DateTimeImmutable('2026-10-15')),
        );
    }

    /** @return array<string, mixed> */
    private function crearReserva(): array
    {
        return $this->service->crear([
            'nombre_huesped' => 'Carlos Pérez',
            'email_huesped' => 'carlos@example.com',
            'nombre_alojamiento' => 'Apartamento Ruzafa',
            'fecha_entrada' => '2026-11-01',
            'fecha_salida' => '2026-11-04',
            'importe' => '300',
        ]);
    }

    public function testCreateStartsPendingAndRecordsEventInTransaction(): void
    {
        $reserva = $this->crearReserva();

        self::assertSame('pendiente', $reserva['estado']);
        self::assertSame(1, $this->transactions->transactions);
        self::assertCount(1, $reserva['eventos']);
        self::assertSame(ReservaService::EVENTO_CREACION, $reserva['eventos'][0]['tipo']);
    }

    public function testInvalidCreateDoesNotTouchStorage(): void
    {
        try {
            $this->service->crear(['nombre_huesped' => 'Solo nombre']);
            self::fail('Se esperaba una ValidationException.');
        } catch (ValidationException) {
            self::assertSame([], $this->reservas->rows);
            self::assertSame([], $this->eventos->eventos);
            self::assertSame(0, $this->transactions->transactions);
        }
    }

    public function testStateChangeRecordsEvent(): void
    {
        $id = $this->crearReserva()['id'];

        $reserva = $this->service->cambiarEstado($id, ['estado' => 'confirmada']);

        self::assertSame('confirmada', $reserva['estado']);
        self::assertCount(2, $reserva['eventos']);
        self::assertSame(ReservaService::EVENTO_CAMBIO_ESTADO, $reserva['eventos'][1]['tipo']);
        self::assertSame('Estado cambiado de pendiente a confirmada.', $reserva['eventos'][1]['descripcion']);
    }

    public function testCancelledReservationCannotChangeAgain(): void
    {
        $id = $this->crearReserva()['id'];
        $this->service->cambiarEstado($id, ['estado' => 'cancelada']);

        $this->expectException(ConflictException::class);
        $this->service->cambiarEstado($id, ['estado' => 'confirmada']);
    }

    public function testSameStateIsAConflictAndRecordsNothing(): void
    {
        $id = $this->crearReserva()['id'];

        try {
            $this->service->cambiarEstado($id, ['estado' => 'pendiente']);
            self::fail('Se esperaba una ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
            self::assertCount(1, $this->eventos->eventos);
        }
    }

    public function testUnknownReservationIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->cambiarEstado(42, ['estado' => 'confirmada']);
    }

    public function testInvalidEstadoValueIsAValidationError(): void
    {
        $id = $this->crearReserva()['id'];

        $this->expectException(ValidationException::class);
        $this->service->cambiarEstado($id, ['estado' => 'borrada']);
    }

    public function testResumenCountsByStateAndExcludesCancelledFromRevenue(): void
    {
        $this->crearReserva();
        $confirmada = $this->crearReserva()['id'];
        $cancelada = $this->crearReserva()['id'];
        $this->service->cambiarEstado($confirmada, ['estado' => 'confirmada']);
        $this->service->cambiarEstado($cancelada, ['estado' => 'cancelada']);

        self::assertSame(
            ['total' => 3, 'pendiente' => 1, 'confirmada' => 1, 'cancelada' => 1, 'ingresos' => '600.00'],
            $this->service->resumen(),
        );
    }

    public function testResumenWithoutReservationsIsAllZeros(): void
    {
        self::assertSame(
            ['total' => 0, 'pendiente' => 0, 'confirmada' => 0, 'cancelada' => 0, 'ingresos' => '0.00'],
            $this->service->resumen(),
        );
    }
}
