<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Cálculo del importe de una estancia. Trabaja en céntimos (enteros) para no
 * introducir errores de coma flotante: "89.90" × 3 = "269.70" exacto.
 */
final class Importe
{
    /** Máximo que cabe en DECIMAL(10,2). */
    public const MAX_CENTIMOS = 9_999_999_999;

    /**
     * @param string $precioNoche Cadena decimal con hasta 2 decimales ("89.9", "120.50").
     * @throws InvalidArgumentException si el precio no es válido, no hay noches o el total no cabe.
     */
    public static function porNoches(string $precioNoche, int $noches): string
    {
        if ($noches < 1) {
            throw new InvalidArgumentException('La estancia debe tener al menos una noche.');
        }

        $total = self::aCentimos($precioNoche) * $noches;
        if ($total > self::MAX_CENTIMOS) {
            throw new InvalidArgumentException('El importe de la estancia es demasiado alto.');
        }

        return self::desdeCentimos($total);
    }

    public static function aCentimos(string $decimal): int
    {
        // Hasta 15 cifras enteras: admite también sumas de varios DECIMAL(10,2) sin desbordar un int de 64 bits.
        if (preg_match('/^(\d{1,15})(?:\.(\d{1,2}))?$/', $decimal, $m) !== 1) {
            throw new InvalidArgumentException("Importe no válido: {$decimal}");
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function desdeCentimos(int $centimos): string
    {
        return intdiv($centimos, 100) . '.' . str_pad((string) ($centimos % 100), 2, '0', STR_PAD_LEFT);
    }
}
