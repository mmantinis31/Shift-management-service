<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application\Contract\ShiftCreationTransactionInterface;
use App\Application\Contract\ShiftNotifierInterface;
use App\Application\CreateShift\CreateShiftCommand;
use App\Application\CreateShift\CreateShiftHandler;
use App\Application\Exception\HolidayDataUnavailable;
use App\Application\Exception\PublicHoliday;
use App\Domain\Entity\Shift;
use App\Domain\Exception\OverlappingShift;
use App\Domain\Repository\ShiftRepositoryInterface;
use App\Tests\Double\FakeHolidayProvider;
use App\Tests\Double\InMemoryShiftRepository;
use PHPUnit\Framework\TestCase;

final class CreateShiftHandlerTest extends TestCase
{
    public function testNotifiesWithThePersistedShift(): void
    {
        $repository = new InMemoryShiftRepository();
        $notifier = $this->createMock(ShiftNotifierInterface::class);
        $notifier->expects(self::once())->method('notify')->with(self::callback(
            static fn (Shift $shift): bool => [$shift] === $repository->shifts,
        ));
        $handler = $this->handler($repository, new FakeHolidayProvider(), $notifier);

        $shift = $handler($this->command());

        self::assertSame([$shift], $repository->shifts);
    }

    public function testPublicHolidayDoesNotStartTransactionOrWrite(): void
    {
        $holidays = new FakeHolidayProvider();
        $holidays->holidays = ['2026-10-06'];
        $handler = $this->handlerWithNoSideEffects($holidays);
        $this->expectException(PublicHoliday::class);

        $handler($this->command());
    }

    public function testHolidayVerificationFailureDoesNotStartTransactionOrWrite(): void
    {
        $holidays = new FakeHolidayProvider();
        $holidays->failure = new HolidayDataUnavailable();
        $handler = $this->handlerWithNoSideEffects($holidays);
        $this->expectException(HolidayDataUnavailable::class);

        $handler($this->command());
    }

    public function testOverlapDoesNotSaveOrNotify(): void
    {
        $repository = $this->createMock(ShiftRepositoryInterface::class);
        $repository->expects(self::once())->method('hasOverlap')->willReturn(true);
        $repository->expects(self::never())->method('save');
        $notifier = $this->createMock(ShiftNotifierInterface::class);
        $notifier->expects(self::never())->method('notify');
        $handler = $this->handler($repository, new FakeHolidayProvider(), $notifier);
        $this->expectException(OverlappingShift::class);

        $handler($this->command());
    }

    private function handler(
        ShiftRepositoryInterface $repository,
        FakeHolidayProvider $holidays,
        ShiftNotifierInterface $notifier,
    ): CreateShiftHandler {
        $transaction = new class implements ShiftCreationTransactionInterface {
            public function run(callable $operation): void
            {
                $operation();
            }
        };

        return new CreateShiftHandler($repository, $holidays, $notifier, $transaction);
    }

    private function handlerWithNoSideEffects(FakeHolidayProvider $holidays): CreateShiftHandler
    {
        $repository = $this->createMock(ShiftRepositoryInterface::class);
        $repository->expects(self::never())->method('hasOverlap');
        $repository->expects(self::never())->method('save');
        $notifier = $this->createMock(ShiftNotifierInterface::class);
        $notifier->expects(self::never())->method('notify');
        $transaction = $this->createMock(ShiftCreationTransactionInterface::class);
        $transaction->expects(self::never())->method('run');

        return new CreateShiftHandler($repository, $holidays, $notifier, $transaction);
    }

    private function command(): CreateShiftCommand
    {
        return new CreateShiftCommand('employee', '2026-10-06', '09:00', '17:00', 'LT');
    }
}
