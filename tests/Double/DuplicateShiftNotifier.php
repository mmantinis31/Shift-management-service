<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Contract\ShiftNotifierInterface;
use App\Domain\Entity\Shift;

final readonly class DuplicateShiftNotifier implements ShiftNotifierInterface
{
    public function __construct(private ShiftNotifierInterface $inner)
    {
    }

    public function notify(Shift $shift): void
    {
        $this->inner->notify($shift);
        $this->inner->notify($shift);
    }
}
