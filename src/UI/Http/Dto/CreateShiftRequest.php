<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

use App\Application\CreateShift\CreateShiftCommand;
use App\Domain\Entity\Shift;
use App\Domain\ValueObject\ShiftPeriod;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateShiftRequest
{
    public function __construct(
        #[SerializedName('employee_id')]
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Shift::EMPLOYEE_ID_MAX_LENGTH)]
        public string $employeeId,
        #[Assert\NotBlank]
        #[Assert\Length(exactly: ShiftPeriod::DATE_LENGTH)]
        #[Assert\Regex(pattern: '/'.ShiftPeriod::DATE_PATTERN.'/D')]
        #[Assert\Date]
        public string $date,
        #[SerializedName('start_time')]
        #[Assert\NotBlank]
        #[Assert\Length(exactly: ShiftPeriod::TIME_LENGTH)]
        #[Assert\Regex(pattern: '/'.ShiftPeriod::TIME_PATTERN.'/D')]
        public string $startTime,
        #[SerializedName('end_time')]
        #[Assert\NotBlank]
        #[Assert\Length(exactly: ShiftPeriod::TIME_LENGTH)]
        #[Assert\Regex(pattern: '/'.ShiftPeriod::TIME_PATTERN.'/D')]
        public string $endTime,
        #[SerializedName('country_code')]
        #[Assert\NotBlank]
        #[Assert\Choice(choices: [Shift::SUPPORTED_COUNTRY_CODE])]
        public string $countryCode,
    ) {
    }

    public function toCommand(): CreateShiftCommand
    {
        return new CreateShiftCommand(
            $this->employeeId,
            $this->date,
            $this->startTime,
            $this->endTime,
            $this->countryCode,
        );
    }
}
