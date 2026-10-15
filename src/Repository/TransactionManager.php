<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Abstrae la transacción para que el servicio no dependa de PDO (y se pueda
 * probar con una implementación en memoria).
 */
interface TransactionManager
{
    /**
     * Ejecuta $operation en una transacción: commit si termina bien,
     * rollback y relanzar la excepción si falla.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
