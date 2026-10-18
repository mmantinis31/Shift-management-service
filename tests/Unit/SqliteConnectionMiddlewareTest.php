<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Adapter\Persistence\SqliteConnectionMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqliteConnectionMiddlewareTest extends TestCase
{
    #[DataProvider('otherDrivers')]
    public function testLeavesOtherConnectionsUntouched(?string $pdoDriver): void
    {
        $native = new \stdClass();

        if (null !== $pdoDriver) {
            $native = $this->createMock(\PDO::class);
            $native->expects(self::once())
                ->method('getAttribute')
                ->with(\PDO::ATTR_DRIVER_NAME)
                ->willReturn($pdoDriver);
        }
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getNativeConnection')->willReturn($native);
        $connection->expects(self::never())->method('exec');
        $connection->expects(self::once())->method('beginTransaction');
        $driver = $this->createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);

        $actual = (new SqliteConnectionMiddleware())->wrap($driver)->connect([]);

        self::assertSame($connection, $actual);
        $actual->beginTransaction();
    }

    /** @return iterable<string, array{?string}> */
    public static function otherDrivers(): iterable
    {
        yield 'PDO MySQL' => ['mysql'];
        yield 'PDO PostgreSQL' => ['pgsql'];
        yield 'non-PDO driver' => [null];
    }
}
