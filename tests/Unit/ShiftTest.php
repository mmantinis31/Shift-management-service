<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Entity\Shift;
use App\Domain\Exception\InvalidShiftInput;
use App\Domain\ValueObject\ShiftPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShiftTest extends TestCase
{
    #[DataProvider('invalidEmployees')]
    public function testRejectsInvalidEmployeeIdentifier(string $employee): void
    {
        $this->expectException(InvalidShiftInput::class);

        new Shift($employee, new ShiftPeriod('2026-10-06', '09:00', '17:00'), 'LT');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEmployees(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'line whitespace' => ["\t\n"];
        yield 'above storage limit' => [str_repeat('a', 256)];
    }

    #[DataProvider('unsupportedCountries')]
    public function testRejectsUnsupportedCountry(string $country): void
    {
        $this->expectException(InvalidShiftInput::class);

        new Shift('employee', new ShiftPeriod('2026-10-06', '09:00', '17:00'), $country);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedCountries(): iterable
    {
        yield 'unsupported' => ['US'];
        yield 'lowercase' => ['lt'];
        yield 'empty' => [''];
    }

    public function testGeneratesDistinctIdentifiers(): void
    {
        $period = new ShiftPeriod('2026-10-06', '09:00', '17:00');
        $first = new Shift('employee', $period, 'LT');
        $second = new Shift('employee', $period, 'LT');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->id);
        self::assertNotSame($first->id, $second->id);
    }
}
