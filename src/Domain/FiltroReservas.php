<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Filtros validados del listado. Todos son opcionales y combinables.
 * anfitrionId no viene de la query string: lo fija el servidor según el
 * usuario autenticado (null = sin acotar, solo para administradores).
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
        public readonly ?int $anfitrionId = null,
    ) {
    }

    public function conAnfitrion(?int $anfitrionId): self
    {
        return new self($this->estado, $this->desde, $this->hasta, $this->q, $this->limit, $anfitrionId);
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
