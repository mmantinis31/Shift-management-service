<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Persistence\ShiftRepository;
use App\Domain\Entity\Shift;
use App\Domain\ValueObject\ShiftPeriod;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShiftRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ShiftRepository $repository;

    protected function setUp(): void
    {
        $this->connection = Database::reset(self::bootKernel());
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(ShiftRepository::class);
    }

    public function testXmlMappingMatchesMigratedShiftTable(): void
    {
        $metadata = $this->entityManager->getClassMetadata(Shift::class);
        $expected = (new SchemaTool($this->entityManager))->getSchemaFromMetadata([$metadata])->getTable('shifts');
        $schemaManager = $this->connection->createSchemaManager();
        $actual = $schemaManager->introspectTable('shifts');
        $difference = $schemaManager->createComparator()->compareTables($actual, $expected);

        self::assertTrue($difference->isEmpty(), 'XML mapping must match the migrated table.');
        self::assertSame(Shift::ID_LENGTH, $metadata->getFieldMapping('id')->length);
        self::assertSame(Shift::EMPLOYEE_ID_MAX_LENGTH, $metadata->getFieldMapping('employeeId')->length);
        self::assertSame(ShiftPeriod::DATE_LENGTH, $metadata->getFieldMapping('period.date')->length);
        self::assertSame(ShiftPeriod::TIME_LENGTH, $metadata->getFieldMapping('period.startTime')->length);
        self::assertSame(ShiftPeriod::TIME_LENGTH, $metadata->getFieldMapping('period.endTime')->length);
    }

    #[DataProvider('employeeIdentifiers')]
    public function testPersistsAndReloadsImmutableShift(string $employee): void
    {
        $shift = $this->shift(employee: $employee);

        $this->repository->save($shift);
        $this->entityManager->clear();
        $loaded = $this->repository->find($shift->id);

        self::assertNotSame($shift, $loaded);
        self::assertEquals($shift, $loaded);
        self::assertSame(1, Database::count($this->connection, 'shifts'));
        self::assertSame($this->repository, $this->entityManager->getRepository(Shift::class));
    }

    /** @return iterable<string, array{string}> */
    public static function employeeIdentifiers(): iterable
    {
        yield 'ordinary identifier' => ['employee'];
        yield 'maximum-length Unicode identifier' => [str_repeat('ė', 255)];
    }

    #[DataProvider('overlapCandidates')]
    public function testQueriesOverlapForExactEmployeeAndDate(
        string $employee,
        string $date,
        string $start,
        string $end,
        bool $expected,
    ): void {
        $this->repository->save($this->shift());
        $candidate = $this->shift($employee, $date, $start, $end);

        $overlaps = $this->repository->hasOverlap($candidate);

        self::assertSame($expected, $overlaps);
        self::assertSame(1, Database::count($this->connection, 'shifts'), 'Overlap lookup must not persist its input.');
    }

    /** @return iterable<string, array{string, string, string, string, bool}> */
    public static function overlapCandidates(): iterable
    {
        yield 'identical interval' => ['employee', '2026-10-06', '09:00', '17:00', true];
        yield 'overlaps start' => ['employee', '2026-10-06', '08:00', '10:00', true];
        yield 'overlaps end' => ['employee', '2026-10-06', '16:59', '18:00', true];
        yield 'contained interval' => ['employee', '2026-10-06', '10:00', '11:00', true];
        yield 'containing interval' => ['employee', '2026-10-06', '08:00', '18:00', true];
        yield 'adjacent before' => ['employee', '2026-10-06', '08:00', '09:00', false];
        yield 'adjacent after' => ['employee', '2026-10-06', '17:00', '18:00', false];
        yield 'different employee' => ['other', '2026-10-06', '09:00', '17:00', false];
        yield 'case-sensitive employee' => ['EMPLOYEE', '2026-10-06', '09:00', '17:00', false];
        yield 'significant trailing space' => ['employee ', '2026-10-06', '09:00', '17:00', false];
        yield 'different date' => ['employee', '2026-10-07', '09:00', '17:00', false];
    }

    private function shift(
        string $employee = 'employee',
        string $date = '2026-10-06',
        string $start = '09:00',
        string $end = '17:00',
    ): Shift {
        return new Shift($employee, new ShiftPeriod($date, $start, $end), 'LT');
    }
}
