<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class PublicHoliday extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Shifts cannot be scheduled on public holidays.');
    }
}
