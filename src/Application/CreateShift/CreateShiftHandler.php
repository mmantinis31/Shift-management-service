<?php

declare(strict_types=1);

namespace App\Application\CreateShift;

use App\Application\Contract\HolidayProviderInterface;
use App\Application\Contract\ShiftCreationTransactionInterface;
use App\Application\Contract\ShiftNotifierInterface;
use App\Application\Exception\PublicHoliday;
use App\Domain\Entity\Shift;
use App\Domain\Exception\OverlappingShift;
use App\Domain\Repository\ShiftRepositoryInterface;
use App\Domain\ValueObject\ShiftPeriod;

final readonly class CreateShiftHandler
{
    public function __construct(
        private ShiftRepositoryInterface $shiftRepository,
        private HolidayProviderInterface $holidays,
        private ShiftNotifierInterface $notifier,
        private ShiftCreationTransactionInterface $transaction,
    ) {
    }

    public function __invoke(CreateShiftCommand $command): Shift
    {
        $shift = new Shift(
            $command->employeeId,
            new ShiftPeriod($command->date, $command->startTime, $command->endTime),
            $command->countryCode,
        );

        if ($this->holidays->isPublicHoliday($shift->period->date, $shift->countryCode)) {
            throw new PublicHoliday();
        }

        $this->transaction->run(function () use ($shift): void {
            if ($this->shiftRepository->hasOverlap($shift)) {
                throw new OverlappingShift();
            }

            $this->shiftRepository->save($shift);
            $this->notifier->notify($shift);
        });

        return $shift;
    }
}
