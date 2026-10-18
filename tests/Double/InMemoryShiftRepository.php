<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Domain\Entity\Shift;
use App\Domain\Repository\ShiftRepositoryInterface;

final class InMemoryShiftRepository implements ShiftRepositoryInterface
{
    /** @var list<Shift> */
    public array $shifts = [];

    public function hasOverlap(Shift $shift): bool
    {
        foreach ($this->shifts as $existing) {
            if ($existing->employeeId === $shift->employeeId && $existing->period->overlaps($shift->period)) {
                return true;
            }
        }

        return false;
    }

    public function save(Shift $shift): void
    {
        $this->shifts[] = $shift;
    }
}
