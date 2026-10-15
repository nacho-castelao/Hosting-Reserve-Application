<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Estados de una reserva y su máquina de estados:
 *
 *   pendiente ──► confirmada ──► cancelada
 *       └──────────────────────────▲
 *
 * cancelada es final. Repetir el estado actual tampoco es una transición válida.
 */
enum EstadoReserva: string
{
    case Pendiente = 'pendiente';
    case Confirmada = 'confirmada';
    case Cancelada = 'cancelada';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pendiente => [self::Confirmada, self::Cancelada],
            self::Confirmada => [self::Cancelada],
            self::Cancelada => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Confirmada => 'Confirmada',
            self::Cancelada => 'Cancelada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::cases());
    }
}
