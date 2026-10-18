<?php

declare(strict_types=1);

namespace App\Adapter\Notification;

use App\Application\Contract\ShiftNotifierInterface;
use App\Domain\Entity\Shift;

final class NullShiftNotifier implements ShiftNotifierInterface
{
    public function notify(Shift $shift): void
    {
    }
}
