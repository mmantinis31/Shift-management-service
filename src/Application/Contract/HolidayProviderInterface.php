<?php

declare(strict_types=1);

namespace App\Application\Contract;

interface HolidayProviderInterface
{
    public function isPublicHoliday(string $date, string $countryCode): bool;
}
