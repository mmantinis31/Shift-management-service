<?php

declare(strict_types=1);

namespace App\Adapter\Client\Nager\Dto;

use App\Domain\ValueObject\ShiftPeriod;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class HolidayResponse
{
    private const string PUBLIC_HOLIDAY_TYPE = 'Public';

    /**
     * @param list<string> $holidayTypes
     */
    public function __construct(
        #[Assert\Date]
        #[Assert\Regex(pattern: '/'.ShiftPeriod::DATE_PATTERN.'/D')]
        public string $date,
        public string $countryCode,
        public bool $nationalHoliday,
        #[Assert\All(constraints: [new Assert\NotNull(), new Assert\Type('string')])]
        public array $holidayTypes,
    ) {
    }

    public function isPublicHoliday(): bool
    {
        return $this->nationalHoliday && in_array(self::PUBLIC_HOLIDAY_TYPE, $this->holidayTypes, true);
    }
}
