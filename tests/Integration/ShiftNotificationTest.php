<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Notification\DatabaseShiftNotifier;
use App\Application\CreateShift\CreateShiftCommand;
use App\Application\CreateShift\CreateShiftHandler;
use App\Domain\Entity\Shift;
use App\Domain\ValueObject\ShiftPeriod;
use App\Tests\Support\Database;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShiftNotificationTest extends KernelTestCase
{
    public function testNotificationCannotReferenceAnUnpersistedShift(): void
    {
        $connection = Database::reset(self::bootKernel());
        $notifier = new DatabaseShiftNotifier($connection);
        $shift = new Shift('employee', new ShiftPeriod('2026-10-06', '09:00', '17:00'), 'LT');
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $notifier->notify($shift);
    }

    #[DataProvider('notificationSettings')]
    public function testNotificationFlagControlsRecordingThroughContainer(bool $enabled): void
    {
        $oldServer = $_SERVER['SHIFT_NOTIFICATIONS_ENABLED'] ?? null;
        $oldEnv = $_ENV['SHIFT_NOTIFICATIONS_ENABLED'] ?? null;
        $_SERVER['SHIFT_NOTIFICATIONS_ENABLED'] = $_ENV['SHIFT_NOTIFICATIONS_ENABLED'] = $enabled ? 'true' : 'false';

        try {
            $connection = Database::reset(self::bootKernel());
            $handler = self::getContainer()->get(CreateShiftHandler::class);

            $shift = $handler(new CreateShiftCommand('employee', '2026-10-06', '09:00', '17:00', 'LT'));

            $expected = $enabled ? [[
                'shift_id' => $shift->id,
                'employee_id' => 'employee',
                'shift_date' => '2026-10-06',
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]] : [];
            self::assertSame(1, Database::count($connection, 'shifts'));
            self::assertSame($expected, $connection->fetchAllAssociative(
                'SELECT shift_id, employee_id, shift_date, start_time, end_time FROM shift_notifications',
            ));
        } finally {
            self::ensureKernelShutdown();

            if (null === $oldServer) {
                unset($_SERVER['SHIFT_NOTIFICATIONS_ENABLED']);
            } else {
                $_SERVER['SHIFT_NOTIFICATIONS_ENABLED'] = $oldServer;
            }

            if (null === $oldEnv) {
                unset($_ENV['SHIFT_NOTIFICATIONS_ENABLED']);
            } else {
                $_ENV['SHIFT_NOTIFICATIONS_ENABLED'] = $oldEnv;
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function notificationSettings(): iterable
    {
        yield 'enabled: persist notification snapshot' => [true];
        yield 'disabled: persist only shift' => [false];
    }
}
