<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

final class PdoTransactionManager implements TransactionManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
