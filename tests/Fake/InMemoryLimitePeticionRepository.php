<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\LimitePeticionRepositoryInterface;

/** Ventana fija con un reloj manipulable ($now, en segundos). */
final class InMemoryLimitePeticionRepository implements LimitePeticionRepositoryInterface
{
    public int $now = 1_000_000;

    /** @var array<string, array{intentos: int, inicio: int}> */
    public array $rows = [];

    public function registrar(string $clave, int $ventanaSegundos): array
    {
        $row = $this->rows[$clave] ?? null;
        if ($row === null || $row['inicio'] <= $this->now - $ventanaSegundos) {
            $row = ['intentos' => 0, 'inicio' => $this->now];
        }
        $row['intentos']++;
        $this->rows[$clave] = $row;

        return ['intentos' => $row['intentos'], 'restante' => max(1, $ventanaSegundos - ($this->now - $row['inicio']))];
    }

    public function limpiar(string $clave): void
    {
        unset($this->rows[$clave]);
    }
}
