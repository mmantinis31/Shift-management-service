<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use App\Application\Contract\ShiftCreationTransactionInterface;
use App\Application\Exception\ShiftStorageBusy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ShiftCreationTransaction implements ShiftCreationTransactionInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function run(callable $operation): void
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            throw new \LogicException('Shift creation must own its transaction.');
        }

        try {
            // The SQLite driver acquires the writer lock before the overlap query.
            $connection->beginTransaction();
            $operation();
            $connection->commit();
        } catch (\Throwable $exception) {
            $this->rollBackAndClear($connection);

            if ($exception instanceof RetryableException) {
                throw new ShiftStorageBusy($exception);
            }

            throw $exception;
        }
    }

    private function rollBackAndClear(Connection $connection): void
    {
        try {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                $connection->close();
            }
        } catch (\Throwable) {
            $connection->close();
        } finally {
            $this->entityManager->clear();
        }
    }
}
