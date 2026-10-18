<?php

declare(strict_types=1);

namespace App\Application\CreateShift;

final readonly class CreateShiftCommand
{
    public function __construct(
        public string $employeeId,
        public string $date,
        public string $startTime,
        public string $endTime,
        public string $countryCode,
    ) {
    }
}
