<?php

declare(strict_types=1);

use App\Adapter\Notification\DatabaseShiftNotifier;
use App\Adapter\Persistence\ShiftCreationTransaction;
use App\Adapter\Persistence\ShiftRepository;
use App\Application\CreateShift\CreateShiftCommand;
use App\Application\CreateShift\CreateShiftHandler;
use App\Application\Exception\ShiftStorageBusy;
use App\Domain\Exception\OverlappingShift;
use App\Kernel;
use App\Tests\Double\BlockingShiftNotifier;
use App\Tests\Double\FakeHolidayProvider;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__).'/bootstrap.php';

if (7 !== $argc) {
    throw new InvalidArgumentException('Expected directory, role, employee, date, start and end arguments.');
}
[$script, $directory, $role, $employee, $date, $start, $end] = $argv;
$kernel = new Kernel('test', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$entityManager = $registry->getManager();
assert($entityManager instanceof EntityManagerInterface);
$connection = $entityManager->getConnection();
$notifier = new DatabaseShiftNotifier($connection);

if ('first' === $role) {
    $notifier = new BlockingShiftNotifier($notifier, $directory);
}
$handler = new CreateShiftHandler(
    new ShiftRepository($registry),
    new FakeHolidayProvider(),
    $notifier,
    new ShiftCreationTransaction($entityManager),
);
$create = static function () use ($handler, $employee, $date, $start, $end): string {
    try {
        $handler(new CreateShiftCommand($employee, $date, $start, $end, 'LT'));

        return 'created';
    } catch (OverlappingShift) {
        return 'overlap';
    }
};

if ('second' === $role) {
    $connection->executeStatement('PRAGMA busy_timeout = 100');

    try {
        $create();
        throw new RuntimeException('A concurrent writer bypassed the occupied SQLite writer lock.');
    } catch (ShiftStorageBusy) {
        if ($connection->isTransactionActive()) {
            throw new RuntimeException('The failed writer left an active transaction.');
        }
        touch($directory.'/second-blocked');
    }
    $deadline = hrtime(true) + 10_000_000_000;

    while (!is_file($directory.'/release')) {
        if (hrtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the first writer to be released.');
        }
        usleep(10000);
        clearstatcache();
    }
    $connection->executeStatement('PRAGMA busy_timeout = 5000');
}

echo json_encode(['result' => $create()], JSON_THROW_ON_ERROR);
