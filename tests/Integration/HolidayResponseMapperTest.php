<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Client\Nager\Dto\HolidayResponse;
use App\Adapter\Client\Nager\HolidayResponseMapper;
use App\Tests\Support\NagerFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HolidayResponseMapperTest extends KernelTestCase
{
    private HolidayResponseMapper $mapper;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->mapper = self::getContainer()->get('test.nager_mapper');
    }

    public function testMapsRequiredFieldsAndIgnoresAdditionalApiMetadata(): void
    {
        $body = NagerFixtures::response([
            'localName' => 'External display name',
            'unrelatedMetadata' => ['value' => 1],
        ]);

        $holidays = $this->mapper->fromJson($body, 'LT', 2026);

        self::assertCount(1, $holidays);
        self::assertEquals(new HolidayResponse('2026-10-06', 'LT', true, ['Public']), $holidays[0]);
    }

    public function testRejectsAnUnreliableEmptyAnnualList(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->mapper->fromJson('[]', 'LT', 2026);
    }

    /** @param list<string> $types */
    #[DataProvider('holidayFlags')]
    public function testBothHolidayFlagsAreRequired(bool $national, array $types, bool $expected): void
    {
        $body = NagerFixtures::response(['nationalHoliday' => $national, 'holidayTypes' => $types]);

        $holidays = $this->mapper->fromJson($body, 'LT', 2026);

        self::assertCount(1, $holidays);
        self::assertSame('2026-10-06', $holidays[0]->date);
        self::assertSame($expected, $holidays[0]->isPublicHoliday());
    }

    /** @return iterable<string, array{bool, list<string>, bool}> */
    public static function holidayFlags(): iterable
    {
        yield 'national public' => [true, ['Public'], true];
        yield 'multiple types' => [true, ['Bank', 'Public'], true];
        yield 'regional public' => [false, ['Public'], false];
        yield 'national observance' => [true, ['Observance'], false];
        yield 'case sensitive type' => [true, ['public'], false];
        yield 'no types' => [true, [], false];
    }

    #[DataProvider('invalidResponses')]
    public function testRejectsInvalidSchema(string $body): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $this->mapper->fromJson($body, 'LT', 2026);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidResponses(): iterable
    {
        yield 'object rather than list' => ['{}'];
        yield 'null' => ['null'];
        yield 'scalar' => ['42'];
        yield 'invalid item' => ['[null]'];
        yield 'list rather than record' => ['[[]]'];
        yield 'empty record' => ['[{}]'];
        yield 'object with numeric keys' => [json_encode((object) [NagerFixtures::holiday()], JSON_THROW_ON_ERROR)];

        foreach (['date', 'countryCode', 'nationalHoliday', 'holidayTypes'] as $field) {
            $record = NagerFixtures::holiday();
            unset($record[$field]);
            yield 'missing '.$field => [json_encode([$record], JSON_THROW_ON_ERROR)];
            yield 'null '.$field => [NagerFixtures::response([$field => null])];
        }
        $invalidFields = [
            'impossible date' => ['date' => '2026-02-30'],
            'noncanonical date' => ['date' => '2026-2-01'],
            'wrong year' => ['date' => '2027-10-06'],
            'zero year' => ['date' => '0000-01-01'],
            'date newline' => ['date' => "2026-10-06\n"],
            'numeric date' => ['date' => 20261006],
            'wrong country' => ['countryCode' => 'LV'],
            'numeric country' => ['countryCode' => 123],
            'true string' => ['nationalHoliday' => 'true'],
            'false string' => ['nationalHoliday' => 'false'],
            'integer true' => ['nationalHoliday' => 1],
            'integer false' => ['nationalHoliday' => 0],
            'float true' => ['nationalHoliday' => 1.0],
            'type scalar' => ['holidayTypes' => 'Public'],
            'numeric type' => ['holidayTypes' => ['Public', 1]],
            'boolean type' => ['holidayTypes' => ['Public', true]],
            'null type' => ['holidayTypes' => ['Public', null]],
            'object type' => ['holidayTypes' => [(object) []]],
            'empty object instead of types' => ['holidayTypes' => (object) []],
            'object instead of types' => ['holidayTypes' => (object) ['type' => 'Public']],
        ];

        foreach ($invalidFields as $name => $override) {
            yield $name => [NagerFixtures::response($override)];
        }
        $partial = [NagerFixtures::holiday(), ['date' => '2026-10-07']];
        yield 'invalid record after valid record' => [json_encode($partial, JSON_THROW_ON_ERROR)];
    }
}
