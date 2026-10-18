<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Contract\ShiftNotifierInterface;
use App\Domain\Entity\Shift;

final readonly class BlockingShiftNotifier implements ShiftNotifierInterface
{
    public function __construct(private ShiftNotifierInterface $inner, private string $directory)
    {
    }

    public function notify(Shift $shift): void
    {
        $this->inner->notify($shift);
        touch($this->directory.'/first-ready');
        $deadline = hrtime(true) + 15_000_000_000;

        while (!is_file($this->directory.'/release')) {
            if (hrtime(true) > $deadline) {
                throw new \RuntimeException('Timed out waiting for test release.');
            }
            usleep(10000);
            clearstatcache();
        }
    }
}
