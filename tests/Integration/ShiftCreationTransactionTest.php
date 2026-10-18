<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Notification\DatabaseShiftNotifier;
use App\Adapter\Persistence\ShiftCreationTransaction;
use App\Application\Contract\ShiftCreationTransactionInterface;
use App\Application\Contract\ShiftNotifierInterface;
use App\Application\CreateShift\CreateShiftCommand;
use App\Application\CreateShift\CreateShiftHandler;
use App\Domain\Entity\Shift;
use App\Domain\Repository\ShiftRepositoryInterface;
use App\Domain\ValueObject\ShiftPeriod;
use App\Tests\Double\DuplicateShiftNotifier;
use App\Tests\Double\FakeHolidayProvider;
use App\Tests\Double\TransactionFailureMiddleware;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShiftCreationTransactionTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->connection = Database::reset(self::bootKernel());
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testNotificationFailureRollsBackShiftAndAllowsRetry(): void
    {
        $notifier = new DuplicateShiftNotifier(new DatabaseShiftNotifier($this->connection));

        try {
            ($this->handler($notifier))($this->command());
            self::fail('Notification failure was ignored.');
        } catch (Exception\UniqueConstraintViolationException) {
            self::assertSame(0, Database::count($this->connection, 'shifts'));
            self::assertSame(0, Database::count($this->connection, 'shift_notifications'));
            self::assertFalse($this->connection->isTransactionActive());
        }

        ($this->handler())($this->command());

        self::assertSame(1, Database::count($this->connection, 'shifts'));
    }

    public function testNestedRollbackPreservesOuterTransaction(): void
    {
        $shift = new Shift('employee', new ShiftPeriod('2026-10-06', '09:00', '17:00'), 'LT');
        $notifier = new DatabaseShiftNotifier($this->connection);
        $transaction = new ShiftCreationTransaction($this->entityManager);

        $transaction->run(function () use ($shift, $notifier): void {
            $this->entityManager->persist($shift);
            $this->entityManager->flush();
            $this->connection->beginTransaction();
            $notifier->notify($shift);
            $this->connection->rollBack();

            self::assertTrue($this->connection->isTransactionActive());
            self::assertSame(1, Database::count($this->connection, 'shifts'));
            self::assertSame(0, Database::count($this->connection, 'shift_notifications'));

            $notifier->notify($shift);
        });

        self::assertFalse($this->connection->isTransactionActive());
        $observer = Database::connect();

        try {
            self::assertSame(1, Database::count($observer, 'shifts'));
            self::assertSame(1, Database::count($observer, 'shift_notifications'));
        } finally {
            $observer->close();
        }
    }

    #[DataProvider('transactionFailures')]
    public function testTransactionFailureLeavesNoWritesOrReusableUncertainConnection(
        string $failOn,
        ?\Throwable $failure = null,
    ): void {
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new TransactionFailureMiddleware($failOn, $failure)]);
        $connection = Database::connect($configuration);
        $entityManager = new EntityManager($connection, $this->entityManager->getConfiguration());
        $shift = new Shift('employee', new ShiftPeriod('2026-10-06', '09:00', '17:00'), 'LT');
        $transaction = new ShiftCreationTransaction($entityManager);
        $operationFailed = new \RuntimeException('Original operation failed.');
        $operation = static function () use ($entityManager, $connection, $shift, $failOn, $operationFailed): void {
            $entityManager->persist($shift);
            $entityManager->flush();
            (new DatabaseShiftNotifier($connection))->notify($shift);

            if ('rollback' === $failOn) {
                throw $operationFailed;
            }
        };

        try {
            try {
                $transaction->run($operation);
                self::fail('The injected transaction failure was ignored.');
            } catch (Exception|\RuntimeException $exception) {
                if ('rollback' === $failOn) {
                    self::assertSame($operationFailed, $exception, 'Rollback must preserve the original exception.');
                } else {
                    self::assertInstanceOf(Exception::class, $exception);
                    self::assertStringContainsString('Injected '.$failOn.' failure.', $exception->getMessage());
                }
            }

            self::assertFalse($connection->isTransactionActive());
            self::assertFalse($connection->isConnected(), 'Uncertain transaction state must be discarded.');
            self::assertFalse($entityManager->contains($shift));

            foreach (['shifts', 'shift_notifications'] as $table) {
                self::assertSame(0, Database::count($this->connection, $table));
            }
        } finally {
            $entityManager->close();
            $connection->close();
        }
    }

    /** @return iterable<string, array{string, ?\Throwable}> */
    public static function transactionFailures(): iterable
    {
        yield 'begin fails' => ['begin', null];
        yield 'commit fails before reaching server' => ['commit', null];
        yield 'rollback fails with driver exception' => ['rollback', null];
        yield 'rollback fails with runtime exception' => ['rollback', new \RuntimeException('Rollback failed.')];
        yield 'rollback fails with error' => ['rollback', new \Error('Rollback failed.')];
    }

    public function testRejectsAnExistingTransactionWithoutRollingItBack(): void
    {
        $shift = new Shift('employee', new ShiftPeriod('2026-10-06', '09:00', '17:00'), 'LT');
        $this->connection->beginTransaction();
        $this->entityManager->persist($shift);

        try {
            try {
                (new ShiftCreationTransaction($this->entityManager))->run(static function (): never {
                    self::fail('The operation must not run inside a transaction it does not own.');
                });
                self::fail('An existing transaction was accepted.');
            } catch (\LogicException $exception) {
                self::assertSame('Shift creation must own its transaction.', $exception->getMessage());
                self::assertTrue($this->connection->isTransactionActive());
                self::assertTrue($this->entityManager->contains($shift));
            }
        } finally {
            $this->connection->rollBack();
            $this->entityManager->clear();
        }
    }

    private function handler(?ShiftNotifierInterface $notifier = null): CreateShiftHandler
    {
        return new CreateShiftHandler(
            self::getContainer()->get(ShiftRepositoryInterface::class),
            new FakeHolidayProvider(),
            $notifier ?? new DatabaseShiftNotifier($this->connection),
            self::getContainer()->get(ShiftCreationTransactionInterface::class),
        );
    }

    private function command(): CreateShiftCommand
    {
        return new CreateShiftCommand('employee', '2026-10-06', '09:00', '17:00', 'LT');
    }
}
