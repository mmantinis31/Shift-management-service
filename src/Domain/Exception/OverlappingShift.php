<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class OverlappingShift extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The employee already has an overlapping shift on this date.');
    }
}
