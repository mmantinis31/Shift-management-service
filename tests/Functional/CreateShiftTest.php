<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Adapter\Notification\DatabaseShiftNotifier;
use App\Application\Contract\HolidayProviderInterface;
use App\Application\Contract\ShiftCreationTransactionInterface;
use App\Application\Contract\ShiftNotifierInterface;
use App\Application\Exception\HolidayDataUnavailable;
use App\Application\Exception\ShiftStorageBusy;
use App\Tests\Double\DuplicateShiftNotifier;
use App\Tests\Double\FakeHolidayProvider;
use App\Tests\Support\Database;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class CreateShiftTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private FakeHolidayProvider $holidays;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::assertNotNull(self::$kernel);
        $this->connection = Database::reset(self::$kernel);
        $this->holidays = self::getContainer()->get(HolidayProviderInterface::class);
    }

    public function testCreatesShiftAndPersistsNotification(): void
    {
        $this->post(self::payload());

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $body = $this->responseBody();
        self::assertIsString($body['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $body['id']);
        self::assertSame(['id' => $body['id']] + self::payload(), $body);
        self::assertSame($body['id'], $this->connection->fetchOne('SELECT id FROM shifts'));
        self::assertSame($body['id'], $this->connection->fetchOne('SELECT shift_id FROM shift_notifications'));
    }

    #[DataProvider('invalidJson')]
    public function testRejectsInvalidJson(string $json, string $code): void
    {
        $this->client->request(
            'POST',
            '/shifts',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $json,
        );

        $this->assertError(400, $code);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidJson(): iterable
    {
        yield 'malformed object' => ['{', 'invalid_json'];
        yield 'empty body' => ['', 'invalid_json'];
        yield 'list instead of object' => ['[]', 'invalid_input'];
        yield 'null body' => ['null', 'invalid_input'];
        yield 'scalar body' => ['"text"', 'invalid_input'];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidRequestData(array $payload): void
    {
        $this->post($payload);

        $body = $this->assertError(400, 'invalid_input');
        self::assertNotEmpty($body['details']);
        self::assertSame(0, Database::count($this->connection, 'shifts'));
        self::assertSame(0, $this->holidays->calls);
        self::assertStringNotContainsString('App\\', $this->responseContent());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        foreach (array_keys(self::payload()) as $field) {
            $missing = self::payload();
            unset($missing[$field]);
            yield 'missing '.$field => [$missing];
            yield 'null '.$field => [self::payload([$field => null])];
        }
        yield 'empty employee' => [self::payload(['employee_id' => ''])];
        yield 'whitespace employee' => [self::payload(['employee_id' => '   '])];
        yield 'employee exceeds storage limit' => [self::payload(['employee_id' => str_repeat('a', 256)])];
        yield 'integer employee' => [self::payload(['employee_id' => 123])];
        yield 'array employee' => [self::payload(['employee_id' => []])];
        yield 'boolean employee' => [self::payload(['employee_id' => true])];
        yield 'invalid date' => [self::payload(['date' => '2026-02-30'])];
        yield 'invalid time' => [self::payload(['start_time' => '9:00'])];
        yield 'hour above range' => [self::payload(['start_time' => '24:00'])];
        yield 'minute above range' => [self::payload(['end_time' => '23:60'])];
        yield 'unsupported country' => [self::payload(['country_code' => 'LV'])];
        yield 'non-canonical employee key' => [self::payload(['employeeId' => 'other'])];
        yield 'unknown field' => [self::payload(['unexpected' => 'field'])];
    }

    public function testRejectsExcessiveShiftDuration(): void
    {
        $this->post(self::payload(['start_time' => '06:00', 'end_time' => '18:01']));

        $this->assertError(422, 'shift_rule_violation');
    }

    public function testOverlapRejectedButAdjacentShiftAcceptedAcrossRequests(): void
    {
        $this->post(self::payload());

        self::assertResponseStatusCodeSame(201);

        $this->post(self::payload(['start_time' => '16:00', 'end_time' => '18:00']));

        $this->assertError(409, 'overlapping_shift');

        $this->post(self::payload(['start_time' => '17:00', 'end_time' => '18:00']));

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, Database::count($this->connection, 'shifts'));
    }

    public function testRejectsPublicHoliday(): void
    {
        $this->holidays->holidays = ['2026-10-06'];

        $this->post(self::payload());

        $this->assertError(422, 'public_holiday');
        self::assertSame(0, Database::count($this->connection, 'shifts'));
    }

    #[DataProvider('holidayProviderFailures')]
    public function testReturnsControlledServiceUnavailable(HolidayDataUnavailable $failure): void
    {
        $this->holidays->failure = $failure;

        $this->post(self::payload());

        $error = $this->assertError(503, 'holiday_data_unavailable');
        self::assertSame([], $error['details']);
        self::assertStringNotContainsString('Internal upstream details', $this->responseContent());
        self::assertResponseHeaderSame('retry-after', '5');
        self::assertSame(0, Database::count($this->connection, 'shifts'));
    }

    /** @return iterable<string, array{HolidayDataUnavailable}> */
    public static function holidayProviderFailures(): iterable
    {
        yield 'unavailable provider' => [new HolidayDataUnavailable()];
        yield 'upstream JSON failure' => [new HolidayDataUnavailable(
            new NotEncodableValueException('Internal upstream details'),
        )];
        yield 'upstream validation failure' => [new HolidayDataUnavailable(
            new ValidationFailedException(null, new ConstraintViolationList([
                new ConstraintViolation('Internal upstream details', null, [], null, 'holidayTypes', null),
            ])),
        )];
    }

    public function testUnexpectedDatabaseFailureDoesNotExposeDetails(): void
    {
        self::getContainer()->set(
            ShiftNotifierInterface::class,
            new DuplicateShiftNotifier(new DatabaseShiftNotifier($this->connection)),
        );

        $this->post(self::payload());

        $error = $this->assertError(500, 'internal_error');
        self::assertSame('An unexpected internal error occurred.', $error['message']);
        self::assertStringNotContainsString('SQLSTATE', $this->responseContent());
        self::assertSame(0, Database::count($this->connection, 'shifts'));
        self::assertSame(0, Database::count($this->connection, 'shift_notifications'));
    }

    public function testStorageContentionReturnsRetryAfterWithoutExposingDriverDetails(): void
    {
        $transaction = $this->createStub(ShiftCreationTransactionInterface::class);
        $transaction->method('run')->willThrowException(new ShiftStorageBusy(new \RuntimeException('Driver details')));
        self::getContainer()->set(ShiftCreationTransactionInterface::class, $transaction);

        $this->post(self::payload());

        $this->assertError(503, 'storage_busy');
        self::assertResponseHeaderSame('retry-after', '5');
        self::assertStringNotContainsString('Driver details', $this->responseContent());
        self::assertSame(0, Database::count($this->connection, 'shifts'));
        self::assertSame(0, Database::count($this->connection, 'shift_notifications'));
    }

    public function testHttpErrorsUseJsonAndPreserveAllowHeader(): void
    {
        $this->client->request('GET', '/shifts');

        $this->assertError(405, 'http_error');
        self::assertResponseHeaderSame('allow', 'POST');
    }

    public function testUnknownRouteReturnsJsonError(): void
    {
        $this->client->request('GET', '/missing');

        $this->assertError(404, 'http_error');
    }

    public function testValidationDetailsUsePublicFieldNames(): void
    {
        $this->post(self::payload(['employee_id' => '', 'start_time' => '24:00', 'end_time' => '23:60']));

        $error = $this->assertError(400, 'invalid_input');
        self::assertSame(
            ['employee_id', 'end_time', 'start_time'],
            array_values(array_unique(array_column($error['details'], 'field'))),
        );
    }

    public function testRequiresJsonContentType(): void
    {
        $this->client->request('POST', '/shifts', content: '{}');

        $this->assertError(415, 'http_error');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function payload(array $overrides = []): array
    {
        return array_replace([
            'employee_id' => 'employee-123',
            'date' => '2026-10-06',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'country_code' => 'LT',
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): void
    {
        $this->client->jsonRequest('POST', '/shifts', $payload);
    }

    private function responseContent(): string
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        return $content;
    }

    /** @return array<array-key, mixed> */
    private function responseBody(): array
    {
        $body = json_decode($this->responseContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /** @return array{code: string, message: string, details: list<array{field: string, message: string}>} */
    private function assertError(int $status, string $code): array
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $body = $this->responseBody();
        self::assertSame(['error'], array_keys($body));
        self::assertIsArray($body['error']);
        $error = $body['error'];
        self::assertSame(['code', 'message', 'details'], array_keys($error));
        self::assertSame($code, $error['code']);
        self::assertIsString($error['message']);
        self::assertIsArray($error['details']);
        self::assertTrue(array_is_list($error['details']));
        $details = [];

        foreach ($error['details'] as $detail) {
            self::assertIsArray($detail);
            self::assertSame(['field', 'message'], array_keys($detail));
            self::assertIsString($detail['field']);
            self::assertIsString($detail['message']);
            self::assertNotSame('', $detail['message']);
            $details[] = ['field' => $detail['field'], 'message' => $detail['message']];
        }

        return ['code' => $error['code'], 'message' => $error['message'], 'details' => $details];
    }
}
