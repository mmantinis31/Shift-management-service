<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Client\Nager\NagerHolidayClient;
use App\Application\Exception\HolidayDataUnavailable;
use App\Tests\Support\NagerFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NagerHolidayClientTest extends KernelTestCase
{
    private MockHttpClient $http;
    private NagerHolidayClient $client;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->http = self::getContainer()->get('http_client.transport');
        $this->client = self::getContainer()->get('test.nager_client');
    }

    public function testConfiguredClientUsesExpectedEndpointTimeoutsAndMapper(): void
    {
        $this->http->setResponseFactory(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://nagerholidays.com/api/v4/Holidays/LT/2026', $url);
            self::assertSame(3.0, $options['timeout']);
            self::assertSame(5.0, $options['max_duration']);
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse(NagerFixtures::response());
        });

        $holidays = $this->client->holidays('LT', 2026);

        self::assertCount(1, $holidays);
        self::assertTrue($holidays[0]->isPublicHoliday());
        self::assertSame(1, $this->http->getRequestsCount());
    }

    #[DataProvider('invalidResponses')]
    public function testMapsHttpAndDecodingFailures(string $body, int $status): void
    {
        $this->http->setResponseFactory([new MockResponse($body, ['http_code' => $status])]);
        $this->expectException(HolidayDataUnavailable::class);

        $this->client->holidays('LT', 2026);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidResponses(): iterable
    {
        yield 'server error' => ['[]', 500];
        yield 'rate limit' => ['[]', 429];
        yield 'redirect' => ['[]', 302];
        yield 'empty response' => ['', 204];
        yield 'invalid JSON' => ['{', 200];
        yield 'invalid schema' => ['{}', 200];
    }

    #[DataProvider('transportFailures')]
    public function testMapsTransportFailures(TransportException $failure): void
    {
        $this->http->setResponseFactory(static function () use ($failure): never {
            throw $failure;
        });
        $this->expectException(HolidayDataUnavailable::class);

        $this->client->holidays('LT', 2026);
    }

    /** @return iterable<string, array{TransportException}> */
    public static function transportFailures(): iterable
    {
        yield 'connection failure' => [new TransportException('Connection failed')];
        yield 'timeout' => [new TimeoutException('Timed out')];
    }

    public function testMapsInterruptedResponseBody(): void
    {
        $body = (static function (): \Generator {
            yield '[ ';
            throw new TransportException('Connection reset during response');
        })();
        $this->http->setResponseFactory([new MockResponse($body)]);
        $this->expectException(HolidayDataUnavailable::class);

        $this->client->holidays('LT', 2026);
    }
}
