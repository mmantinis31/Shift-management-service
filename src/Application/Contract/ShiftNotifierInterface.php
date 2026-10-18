<?php

declare(strict_types=1);

namespace App\Application\Contract;

use App\Domain\Entity\Shift;

interface ShiftNotifierInterface
{
    public function notify(Shift $shift): void;
}
