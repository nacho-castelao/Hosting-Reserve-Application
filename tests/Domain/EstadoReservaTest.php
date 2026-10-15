<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\EstadoReserva;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstadoReservaTest extends TestCase
{
    #[DataProvider('transitions')]
    public function testTransitions(EstadoReserva $from, EstadoReserva $to, bool $allowed): void
    {
        self::assertSame($allowed, $from->canTransitionTo($to));
    }

    /** @return iterable<string, array{EstadoReserva, EstadoReserva, bool}> */
    public static function transitions(): iterable
    {
        yield 'pendiente → confirmada' => [EstadoReserva::Pendiente, EstadoReserva::Confirmada, true];
        yield 'pendiente → cancelada' => [EstadoReserva::Pendiente, EstadoReserva::Cancelada, true];
        yield 'confirmada → cancelada' => [EstadoReserva::Confirmada, EstadoReserva::Cancelada, true];

        yield 'pendiente → pendiente' => [EstadoReserva::Pendiente, EstadoReserva::Pendiente, false];
        yield 'confirmada → pendiente' => [EstadoReserva::Confirmada, EstadoReserva::Pendiente, false];
        yield 'confirmada → confirmada' => [EstadoReserva::Confirmada, EstadoReserva::Confirmada, false];
        yield 'cancelada → pendiente' => [EstadoReserva::Cancelada, EstadoReserva::Pendiente, false];
        yield 'cancelada → confirmada' => [EstadoReserva::Cancelada, EstadoReserva::Confirmada, false];
        yield 'cancelada → cancelada' => [EstadoReserva::Cancelada, EstadoReserva::Cancelada, false];
    }

    public function testCanceladaIsFinal(): void
    {
        self::assertSame([], EstadoReserva::Cancelada->allowedTransitions());
    }
}
