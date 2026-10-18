<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Exception\InvalidShiftInput;
use App\Domain\Exception\ShiftRuleViolation;
use App\Domain\ValueObject\ShiftPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShiftPeriodTest extends TestCase
{
    #[DataProvider('invalidDates')]
    public function testRejectsInvalidCalendarDate(string $date): void
    {
        $this->expectException(InvalidShiftInput::class);

        new ShiftPeriod($date, '09:00', '17:00');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDates(): iterable
    {
        yield 'single-digit month' => ['2026-2-01'];
        yield 'impossible day' => ['2026-02-30'];
        yield 'non-leap year' => ['2025-02-29'];
        yield 'month above range' => ['2026-13-01'];
        yield 'zero month' => ['2026-00-01'];
        yield 'zero day' => ['2026-01-00'];
        yield 'zero year' => ['0000-01-01'];
        yield 'reversed date' => ['06-10-2026'];
        yield 'timestamp' => ['2026-10-06T00:00:00'];
        yield 'trailing newline' => ["2026-10-06\n"];
        yield 'empty date' => [''];
    }

    #[DataProvider('invalidTimes')]
    public function testRejectsInvalidTime(string $start, string $end, string $field): void
    {
        try {
            new ShiftPeriod('2026-10-06', $start, $end);
            self::fail('An invalid time was accepted.');
        } catch (InvalidShiftInput $exception) {
            self::assertSame($field, $exception->field);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidTimes(): iterable
    {
        $times = [
            'single-digit hour' => '9:00',
            'single-digit minute' => '09:0',
            'hour above range' => '24:00',
            'minute above range' => '12:60',
            'seconds' => '09:00:00',
            'leading whitespace' => ' 09:00',
            'trailing newline' => "09:00\n",
            'empty time' => '',
        ];

        foreach ($times as $name => $time) {
            yield 'start: '.$name => [$time, '17:00', 'start_time'];
            yield 'end: '.$name => ['09:00', $time, 'end_time'];
        }
    }

    #[DataProvider('invalidPeriods')]
    public function testRejectsInvalidDuration(string $start, string $end): void
    {
        $this->expectException(ShiftRuleViolation::class);

        new ShiftPeriod('2026-10-06', $start, $end);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPeriods(): iterable
    {
        yield 'zero duration' => ['09:00', '09:00'];
        yield 'end before start' => ['17:00', '09:00'];
        yield 'overnight shift' => ['23:00', '01:00'];
        yield 'one minute beyond maximum' => ['06:00', '18:01'];
    }

    #[DataProvider('validPeriods')]
    public function testAcceptsValidPeriod(string $date, string $start, string $end): void
    {
        $period = new ShiftPeriod($date, $start, $end);

        self::assertSame($date, $period->date);
        self::assertSame($start, $period->startTime);
        self::assertSame($end, $period->endTime);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validPeriods(): iterable
    {
        yield 'leap day' => ['2028-02-29', '09:00', '17:00'];
        yield 'exactly twelve hours' => ['2026-10-06', '00:00', '12:00'];
        yield 'one minute' => ['2026-10-06', '09:00', '09:01'];
        yield 'last minute of day' => ['2026-10-06', '23:58', '23:59'];
    }

    #[DataProvider('overlapPeriods')]
    public function testComparesPeriodsSymmetrically(string $date, string $start, string $end, bool $expected): void
    {
        $existing = new ShiftPeriod('2026-10-06', '09:00', '17:00');
        $candidate = new ShiftPeriod($date, $start, $end);

        self::assertSame($expected, $existing->overlaps($candidate));
        self::assertSame($expected, $candidate->overlaps($existing));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function overlapPeriods(): iterable
    {
        yield 'adjacent before' => ['2026-10-06', '08:00', '09:00', false];
        yield 'adjacent after' => ['2026-10-06', '17:00', '18:00', false];
        yield 'different date' => ['2026-10-07', '09:00', '17:00', false];
        yield 'overlaps start' => ['2026-10-06', '08:00', '10:00', true];
        yield 'overlaps end' => ['2026-10-06', '16:59', '18:00', true];
        yield 'contained interval' => ['2026-10-06', '10:00', '11:00', true];
        yield 'containing interval' => ['2026-10-06', '08:00', '18:00', true];
        yield 'identical interval' => ['2026-10-06', '09:00', '17:00', true];
    }
}
