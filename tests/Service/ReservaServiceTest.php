<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Domain\Rol;
use App\Domain\UsuarioActual;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeTransactionManager;
use Tests\Fake\InMemoryAlojamientoRepository;
use Tests\Fake\InMemoryReservaEventoRepository;
use Tests\Fake\InMemoryReservaRepository;

final class ReservaServiceTest extends TestCase
{
    private const ANFITRION_A = 1;
    private const ANFITRION_B = 2;

    private InMemoryAlojamientoRepository $alojamientos;
    private InMemoryReservaRepository $reservas;
    private InMemoryReservaEventoRepository $eventos;
    private FakeTransactionManager $transactions;
    private ReservaService $service;

    private int $alojamientoA;
    private int $alojamientoB;
    private UsuarioActual $anfitrionA;
    private UsuarioActual $anfitrionB;
    private UsuarioActual $admin;

    protected function setUp(): void
    {
        $this->alojamientos = new InMemoryAlojamientoRepository();
        $this->reservas = new InMemoryReservaRepository($this->alojamientos);
        $this->eventos = new InMemoryReservaEventoRepository();
        $this->transactions = new FakeTransactionManager();
        $this->service = new ReservaService(
            $this->reservas,
            $this->eventos,
            $this->alojamientos,
            $this->transactions,
            new ReservaValidator(new DateTimeImmutable('2026-10-15')),
        );

        $this->alojamientoA = $this->alojamientos->add(self::ANFITRION_A, '100.00');
        $this->alojamientoB = $this->alojamientos->add(self::ANFITRION_B, '89.90', nombre: 'Hotel Triana');
        $this->anfitrionA = new UsuarioActual(self::ANFITRION_A, 'Ana', 'ana@example.com', Rol::Anfitrion);
        $this->anfitrionB = new UsuarioActual(self::ANFITRION_B, 'Bruno', 'bruno@example.com', Rol::Anfitrion);
        $this->admin = new UsuarioActual(99, 'Admin', 'admin@example.com', Rol::Admin);
    }

    /** @return array<string, mixed> */
    private function crearReserva(?int $alojamiento = null, string $entrada = '2026-11-01', string $salida = '2026-11-04'): array
    {
        return $this->service->crear([
            'alojamiento_id' => $alojamiento ?? $this->alojamientoA,
            'nombre_huesped' => 'Carlos Pérez',
            'email_huesped' => 'carlos@example.com',
            'fecha_entrada' => $entrada,
            'fecha_salida' => $salida,
        ]);
    }

    public function testCreateStartsPendingAndRecordsGuestEventInTransaction(): void
    {
        $reserva = $this->crearReserva();

        self::assertSame('pendiente', $reserva['estado']);
        self::assertSame(1, $this->transactions->transactions);
        self::assertCount(1, $this->eventos->eventos);
        self::assertSame(ReservaService::EVENTO_CREACION, $this->eventos->eventos[0]['tipo']);
        self::assertNull($this->eventos->eventos[0]['usuario_id'], 'El alta la hace el huésped, sin usuario.');
    }

    public function testPriceIsNightsTimesPricePerNight(): void
    {
        $reserva = $this->crearReserva($this->alojamientoB, '2026-11-01', '2026-11-04');

        self::assertSame('269.70', $reserva['importe']);
    }

    public function testGuestResponseDoesNotExposeInternalData(): void
    {
        $reserva = $this->crearReserva();

        self::assertArrayNotHasKey('anfitrion_id', $reserva);
        self::assertArrayNotHasKey('eventos', $reserva);
    }

    public function testClientCannotSetThePrice(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->crear([
            'alojamiento_id' => $this->alojamientoA,
            'nombre_huesped' => 'Carlos Pérez',
            'email_huesped' => 'carlos@example.com',
            'fecha_entrada' => '2026-11-01',
            'fecha_salida' => '2026-11-04',
            'importe' => '0.01',
        ]);
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

    public function testUnknownAccommodationIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->crearReserva(404);
    }

    public function testInactiveAccommodationCannotBeBooked(): void
    {
        $inactivo = $this->alojamientos->add(self::ANFITRION_A, '50.00', activo: false);

        $this->expectException(NotFoundException::class);
        $this->crearReserva($inactivo);
    }

    public function testOverlappingStayIsAConflict(): void
    {
        $this->crearReserva(entrada: '2026-11-01', salida: '2026-11-04');

        try {
            $this->crearReserva(entrada: '2026-11-03', salida: '2026-11-06');
            self::fail('Se esperaba una ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
            self::assertCount(1, $this->reservas->rows);
        }
    }

    public function testCheckoutDayCanBeBookedAsCheckin(): void
    {
        $this->crearReserva(entrada: '2026-11-01', salida: '2026-11-04');

        $reserva = $this->crearReserva(entrada: '2026-11-04', salida: '2026-11-06');

        self::assertSame('pendiente', $reserva['estado']);
    }

    public function testCancelledReservationFreesTheDates(): void
    {
        $id = $this->crearReserva()['id'];
        $this->service->cambiarEstado($id, ['estado' => 'cancelada'], $this->anfitrionA);

        $reserva = $this->crearReserva();

        self::assertSame('pendiente', $reserva['estado']);
    }

    public function testSameDatesInAnotherAccommodationAreFine(): void
    {
        $this->crearReserva($this->alojamientoA);

        $reserva = $this->crearReserva($this->alojamientoB);

        self::assertSame('pendiente', $reserva['estado']);
    }

    public function testStateChangeRecordsEventWithUser(): void
    {
        $id = $this->crearReserva()['id'];

        $reserva = $this->service->cambiarEstado($id, ['estado' => 'confirmada'], $this->anfitrionA);

        self::assertSame('confirmada', $reserva['estado']);
        self::assertCount(2, $reserva['eventos']);
        self::assertSame(ReservaService::EVENTO_CAMBIO_ESTADO, $reserva['eventos'][1]['tipo']);
        self::assertSame('Estado cambiado de pendiente a confirmada.', $reserva['eventos'][1]['descripcion']);
        self::assertSame(self::ANFITRION_A, $reserva['eventos'][1]['usuario_id']);
    }

    public function testHostCannotSeeOrChangeAnotherHostsReservation(): void
    {
        $id = $this->crearReserva($this->alojamientoA)['id'];

        try {
            $this->service->obtener($id, $this->anfitrionB);
            self::fail('Se esperaba una NotFoundException.');
        } catch (NotFoundException) {
        }

        try {
            $this->service->cambiarEstado($id, ['estado' => 'cancelada'], $this->anfitrionB);
            self::fail('Se esperaba una NotFoundException.');
        } catch (NotFoundException) {
            self::assertSame('pendiente', $this->reservas->rows[$id]['estado']);
        }
    }

    public function testHostListsOnlyOwnReservationsAndAdminListsAll(): void
    {
        $this->crearReserva($this->alojamientoA);
        $this->crearReserva($this->alojamientoB);
        $filtro = $this->service->parseFiltros([]);

        self::assertSame(1, $this->service->listar($filtro, $this->anfitrionA)['total']);
        self::assertSame(1, $this->service->listar($filtro, $this->anfitrionB)['total']);
        self::assertSame(2, $this->service->listar($filtro, $this->admin)['total']);
    }

    public function testAdminCanChangeAnyReservation(): void
    {
        $id = $this->crearReserva($this->alojamientoB)['id'];

        $reserva = $this->service->cambiarEstado($id, ['estado' => 'confirmada'], $this->admin);

        self::assertSame('confirmada', $reserva['estado']);
    }

    public function testCancelledReservationCannotChangeAgain(): void
    {
        $id = $this->crearReserva()['id'];
        $this->service->cambiarEstado($id, ['estado' => 'cancelada'], $this->anfitrionA);

        $this->expectException(ConflictException::class);
        $this->service->cambiarEstado($id, ['estado' => 'confirmada'], $this->anfitrionA);
    }

    public function testSameStateIsAConflictAndRecordsNothing(): void
    {
        $id = $this->crearReserva()['id'];

        try {
            $this->service->cambiarEstado($id, ['estado' => 'pendiente'], $this->anfitrionA);
            self::fail('Se esperaba una ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getStatusCode());
            self::assertCount(1, $this->eventos->eventos);
        }
    }

    public function testUnknownReservationIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->cambiarEstado(42, ['estado' => 'confirmada'], $this->admin);
    }

    public function testInvalidEstadoValueIsAValidationError(): void
    {
        $id = $this->crearReserva()['id'];

        $this->expectException(ValidationException::class);
        $this->service->cambiarEstado($id, ['estado' => 'borrada'], $this->anfitrionA);
    }

    public function testResumenIsScopedAndExcludesCancelledFromRevenue(): void
    {
        $this->crearReserva(entrada: '2026-11-01', salida: '2026-11-04');
        $confirmada = $this->crearReserva(entrada: '2026-11-10', salida: '2026-11-13')['id'];
        $cancelada = $this->crearReserva(entrada: '2026-11-20', salida: '2026-11-23')['id'];
        $this->service->cambiarEstado($confirmada, ['estado' => 'confirmada'], $this->anfitrionA);
        $this->service->cambiarEstado($cancelada, ['estado' => 'cancelada'], $this->anfitrionA);
        $this->crearReserva($this->alojamientoB);

        self::assertSame(
            ['total' => 3, 'pendiente' => 1, 'confirmada' => 1, 'cancelada' => 1, 'ingresos' => '600.00'],
            $this->service->resumen($this->anfitrionA),
        );
        self::assertSame(4, $this->service->resumen($this->admin)['total']);
        self::assertSame('869.70', $this->service->resumen($this->admin)['ingresos']);
    }

    public function testResumenWithoutReservationsIsAllZeros(): void
    {
        self::assertSame(
            ['total' => 0, 'pendiente' => 0, 'confirmada' => 0, 'cancelada' => 0, 'ingresos' => '0.00'],
            $this->service->resumen($this->anfitrionA),
        );
    }
}
