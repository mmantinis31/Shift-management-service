<?php

declare(strict_types=1);

namespace App\Adapter\Notification;

use App\Application\Contract\ShiftNotifierInterface;
use App\Domain\Entity\Shift;
use Doctrine\DBAL\Connection;

final readonly class DatabaseShiftNotifier implements ShiftNotifierInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function notify(Shift $shift): void
    {
        $this->connection->insert('shift_notifications', [
            'shift_id' => $shift->id,
            'employee_id' => $shift->employeeId,
            'shift_date' => $shift->period->date,
            'start_time' => $shift->period->startTime,
            'end_time' => $shift->period->endTime,
        ]);
    }
}
