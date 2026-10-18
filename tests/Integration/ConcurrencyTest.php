<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Persistence\ShiftCreationTransaction;
use App\Application\Exception\ShiftStorageBusy;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ConcurrencyTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = Database::reset(self::bootKernel());
    }

    #[DataProvider('concurrentPeriods')]
    public function testSerializesWritersAndRechecksCommittedSchedule(
        string $employee,
        string $date,
        string $start,
        string $end,
        string $result,
        int $count,
    ): void {
        $directory = dirname(__DIR__, 2).'/var/concurrency-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($directory);
        $workers = [];

        try {
            $first = $this->startWorker($directory, 'first', 'employee', '2026-10-06', '09:00', '17:00');
            $workers[] = $first;
            $this->waitForFile($directory.'/first-ready', $first);
            $second = $this->startWorker($directory, 'second', $employee, $date, $start, $end);
            $workers[] = $second;
            $this->waitForFile($directory.'/second-blocked', $second);

            // The signal is emitted only after SQLite refuses the second writer, not after an arbitrary sleep.
            self::assertTrue($first->isRunning());
            self::assertTrue($second->isRunning());
            self::assertSame(
                0,
                Database::count($this->connection, 'shifts'),
                'Readers must not see uncommitted shifts.',
            );
            self::assertSame(0, Database::count($this->connection, 'shift_notifications'));

            touch($directory.'/release');
            self::assertSame('created', $this->finishWorker($first));
            self::assertSame($result, $this->finishWorker($second));
            self::assertSame($count, Database::count($this->connection, 'shifts'));
            self::assertSame($count, Database::count($this->connection, 'shift_notifications'));
        } finally {
            touch($directory.'/release');

            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            (new Filesystem())->remove($directory);
        }
    }

    /** @return iterable<string, array{string, string, string, string, string, int}> */
    public static function concurrentPeriods(): iterable
    {
        yield 'overlap rejected after first commit' => ['employee', '2026-10-06', '10:00', '11:00', 'overlap', 1];
        yield 'adjacent accepted after first commit' => ['employee', '2026-10-06', '17:00', '18:00', 'created', 2];
        yield 'different employee also waits' => ['other', '2026-10-06', '09:00', '17:00', 'created', 2];
    }

    public function testWriterLockIsAcquiredBeforeCallingOperation(): void
    {
        $locker = Database::connect();
        $locker->beginTransaction();
        $this->connection->executeStatement('PRAGMA busy_timeout = 100');
        $transaction = new ShiftCreationTransaction(self::getContainer()->get(EntityManagerInterface::class));
        $this->expectException(ShiftStorageBusy::class);

        try {
            $transaction->run(static function (): void {
                self::fail('The operation must not run before acquiring the SQLite writer lock.');
            });
        } finally {
            $locker->rollBack();
            $locker->close();
            self::assertFalse($this->connection->isTransactionActive());
            self::assertSame(0, Database::count($this->connection, 'shifts'));
            self::assertSame(0, Database::count($this->connection, 'shift_notifications'));
        }
    }

    private function startWorker(
        string $directory,
        string $role,
        string $employee,
        string $date,
        string $start,
        string $end,
    ): Process {
        $process = new Process(
            [
                PHP_BINARY, dirname(__DIR__).'/Fixtures/ConcurrentShiftWorker.php',
                $directory, $role, $employee, $date, $start, $end,
            ],
            env: ['APP_ENV' => 'test', 'DATABASE_URL' => Database::url()],
            timeout: 15,
        );
        $process->start();

        return $process;
    }

    private function waitForFile(string $path, Process $worker): void
    {
        $deadline = hrtime(true) + 10_000_000_000;

        while (!is_file($path)) {
            if (!$worker->isRunning()) {
                self::fail('Worker exited before synchronization: '.$worker->getErrorOutput());
            }

            if (hrtime(true) > $deadline) {
                self::fail('Worker did not reach synchronization: '.$path.' '.$worker->getErrorOutput());
            }
            usleep(10000);
            clearstatcache();
        }
    }

    private function finishWorker(Process $worker): string
    {
        self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsString($result['result']);

        return $result['result'];
    }
}
