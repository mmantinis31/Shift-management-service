<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class NagerFixtures
{
    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function holiday(array $overrides = []): array
    {
        return array_replace([
            'date' => '2026-10-06',
            'countryCode' => 'LT',
            'nationalHoliday' => true,
            'holidayTypes' => ['Public'],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    public static function response(array $overrides = []): string
    {
        return json_encode([self::holiday($overrides)], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
