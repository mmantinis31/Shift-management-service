<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Exception\InvalidShiftInput;
use App\Domain\ValueObject\ShiftPeriod;

final class Shift
{
    public const int EMPLOYEE_ID_MAX_LENGTH = 255;
    public const string SUPPORTED_COUNTRY_CODE = 'LT';
    public const int ID_LENGTH = self::ID_BYTES * 2;
    private const int ID_BYTES = 16;

    public readonly string $id;

    public function __construct(
        public readonly string $employeeId,
        public readonly ShiftPeriod $period,
        public readonly string $countryCode,
    ) {
        if ('' === trim($employeeId)) {
            throw new InvalidShiftInput('employee_id', 'Employee ID must not be empty.');
        }

        if (mb_strlen($employeeId, 'UTF-8') > self::EMPLOYEE_ID_MAX_LENGTH) {
            $message = sprintf('Employee ID cannot exceed %d characters.', self::EMPLOYEE_ID_MAX_LENGTH);
            throw new InvalidShiftInput('employee_id', $message);
        }

        if (self::SUPPORTED_COUNTRY_CODE !== $countryCode) {
            throw new InvalidShiftInput('country_code', 'Only Lithuania (LT) is supported.');
        }

        $this->id = bin2hex(random_bytes(self::ID_BYTES));
    }
}
