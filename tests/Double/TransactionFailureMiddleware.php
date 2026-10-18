<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final readonly class TransactionFailureMiddleware implements Middleware
{
    public function __construct(private string $failOn, private ?\Throwable $failure = null)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->failOn, $this->failure) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly string $failOn,
                private readonly ?\Throwable $failure,
            ) {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);

                return new class ($connection, $this->failOn, $this->failure) extends AbstractConnectionMiddleware {
                    public function __construct(
                        Connection $connection,
                        private readonly string $failOn,
                        private readonly ?\Throwable $failure,
                    ) {
                        parent::__construct($connection);
                    }

                    public function beginTransaction(): void
                    {
                        $this->failIfRequested('begin');
                        parent::beginTransaction();
                    }

                    public function commit(): void
                    {
                        $this->failIfRequested('commit');
                        parent::commit();
                    }

                    public function rollBack(): void
                    {
                        $this->failIfRequested('rollback');
                        parent::rollBack();
                    }

                    private function failIfRequested(string $operation): void
                    {
                        if ($operation === $this->failOn) {
                            if (null !== $this->failure) {
                                throw $this->failure;
                            }
                            $message = 'Injected '.$operation.' failure.';
                            throw new class ($message) extends \RuntimeException implements Exception {
                                public function getSQLState(): ?string
                                {
                                    return null;
                                }
                            };
                        }
                    }
                };
            }
        };
    }
}
