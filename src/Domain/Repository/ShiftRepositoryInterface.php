<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Shift;

interface ShiftRepositoryInterface
{
    public function hasOverlap(Shift $shift): bool;

    public function save(Shift $shift): void;
}
