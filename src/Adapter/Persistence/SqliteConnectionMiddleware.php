<?php

declare(strict_types=1);

namespace App\Adapter\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Application-wide PDO SQLite policy: every outer DBAL transaction reserves the writer,
 * including migrations and transactions outside shift creation. Nested transactions
 * remain DBAL-managed savepoints. Other database connections are left unchanged.
 */
#[AsMiddleware]
final readonly class SqliteConnectionMiddleware implements Middleware
{
    public function __construct(private int $busyTimeoutMilliseconds = 5000)
    {
        if ($busyTimeoutMilliseconds < 1) {
            throw new \InvalidArgumentException('SQLite busy timeout must be positive.');
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->busyTimeoutMilliseconds) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly int $busyTimeoutMilliseconds)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                $native = $connection->getNativeConnection();

                if (!$native instanceof \PDO || 'sqlite' !== $native->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                    return $connection;
                }
                $connection->exec('PRAGMA busy_timeout = '.$this->busyTimeoutMilliseconds);
                $connection->exec('PRAGMA foreign_keys = ON');
                $connection->exec('PRAGMA journal_mode = WAL');

                return new class ($connection) extends AbstractConnectionMiddleware {
                    public function beginTransaction(): void
                    {
                        $this->exec('BEGIN IMMEDIATE');
                    }
                };
            }
        };
    }
}
