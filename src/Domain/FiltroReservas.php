<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Filtros validados del listado. Todos son opcionales y combinables.
 */
final class FiltroReservas
{
    public const LIMIT_DEFAULT = 50;
    public const LIMIT_MAX = 100;

    public function __construct(
        public readonly ?EstadoReserva $estado = null,
        public readonly ?DateTimeImmutable $desde = null,
        public readonly ?DateTimeImmutable $hasta = null,
        public readonly ?string $q = null,
        public readonly int $limit = self::LIMIT_DEFAULT,
    ) {
    }

    /** Valores normalizados (para rellenar el formulario de filtros en la vista). */
    public function toArray(): array
    {
        return [
            'estado' => $this->estado?->value ?? '',
            'desde' => $this->desde?->format('Y-m-d') ?? '',
            'hasta' => $this->hasta?->format('Y-m-d') ?? '',
            'q' => $this->q ?? '',
            'limit' => $this->limit,
        ];
    }
}
