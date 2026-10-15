<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\TransactionManager;

/** Ejecuta la operación directamente y cuenta cuántas transacciones se abrieron. */
final class FakeTransactionManager implements TransactionManager
{
    public int $transactions = 0;

    public function transactional(callable $operation): mixed
    {
        $this->transactions++;

        return $operation();
    }
}
