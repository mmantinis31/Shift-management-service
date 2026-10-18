<?php

declare(strict_types=1);

namespace App\Adapter\Notification;

use App\Application\Contract\ShiftNotifierInterface;

final class ShiftNotifierFactory
{
    public static function create(
        bool $enabled,
        DatabaseShiftNotifier $database,
        NullShiftNotifier $null,
    ): ShiftNotifierInterface {
        return $enabled ? $database : $null;
    }
}
