<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class ShiftStorageBusy extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('Shift storage is busy. Please retry shortly.', 0, $previous);
    }
}
