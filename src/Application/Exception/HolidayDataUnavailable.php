<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class HolidayDataUnavailable extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Public holiday eligibility is temporarily unavailable.', 0, $previous);
    }
}
