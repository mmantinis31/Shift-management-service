<?php

declare(strict_types=1);

namespace App\Application\Contract;

interface ShiftCreationTransactionInterface
{
    /**
     * @param callable(): void $operation
     */
    public function run(callable $operation): void;
}
