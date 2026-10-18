<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

use App\Domain\Entity\Shift;
use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class ShiftResponse
{
    public function __construct(
        public string $id,
        #[SerializedName('employee_id')]
        public string $employeeId,
        public string $date,
        #[SerializedName('start_time')]
        public string $startTime,
        #[SerializedName('end_time')]
        public string $endTime,
        #[SerializedName('country_code')]
        public string $countryCode,
    ) {
    }

    public static function fromShift(Shift $shift): self
    {
        return new self(
            $shift->id,
            $shift->employeeId,
            $shift->period->date,
            $shift->period->startTime,
            $shift->period->endTime,
            $shift->countryCode,
        );
    }
}
