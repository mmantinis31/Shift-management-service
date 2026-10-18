<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Exception\InvalidShiftInput;
use App\Domain\Exception\ShiftRuleViolation;

final class ShiftPeriod
{
    public const string DATE_PATTERN = '^[0-9]{4}-[0-9]{2}-[0-9]{2}$';
    public const string TIME_PATTERN = '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$';
    public const int DATE_LENGTH = 10;
    public const int TIME_LENGTH = 5;
    public const int MAX_DURATION_HOURS = 12;
    private const int MAX_DURATION_MINUTES = self::MAX_DURATION_HOURS * 60;

    public function __construct(
        public readonly string $date,
        public readonly string $startTime,
        public readonly string $endTime,
    ) {
        if (!preg_match('/'.self::DATE_PATTERN.'/D', $date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidShiftInput('date', 'Date must be a valid calendar date in YYYY-MM-DD format.');
        }

        foreach (['start_time' => $startTime, 'end_time' => $endTime] as $field => $time) {
            if (!preg_match('/'.self::TIME_PATTERN.'/D', $time)) {
                throw new InvalidShiftInput($field, 'Time must use the 24-hour HH:mm format.');
            }
        }

        $duration = self::minutes($endTime) - self::minutes($startTime);

        if ($duration <= 0) {
            throw new ShiftRuleViolation('End time must be later than start time on the same day.');
        }

        if ($duration > self::MAX_DURATION_MINUTES) {
            throw new ShiftRuleViolation(sprintf('A shift cannot exceed %d hours.', self::MAX_DURATION_HOURS));
        }
    }

    public function overlaps(self $other): bool
    {
        return $this->date === $other->date
            && $this->startTime < $other->endTime
            && $this->endTime > $other->startTime;
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
