<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Contract\HolidayProviderInterface;
use App\Application\Exception\HolidayDataUnavailable;

final class FakeHolidayProvider implements HolidayProviderInterface
{
    /** @var list<string> */
    public array $holidays = [];
    public ?HolidayDataUnavailable $failure = null;
    public int $calls = 0;

    public function isPublicHoliday(string $date, string $countryCode): bool
    {
        ++$this->calls;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return in_array($date, $this->holidays, true);
    }
}
