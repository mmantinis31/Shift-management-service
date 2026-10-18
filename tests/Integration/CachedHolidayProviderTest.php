<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\Client\Nager\NagerHolidayClient;
use App\Adapter\Holiday\CachedHolidayProvider;
use App\Application\Exception\HolidayDataUnavailable;
use App\Tests\Support\NagerFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CachedHolidayProviderTest extends KernelTestCase
{
    private MockHttpClient $http;
    private NagerHolidayClient $client;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->http = self::getContainer()->get('http_client.transport');
        $this->client = self::getContainer()->get('test.nager_client');
    }

    public function testCachesValidatedDatesByYear(): void
    {
        $this->http->setResponseFactory([
            new MockResponse(NagerFixtures::response()),
            new MockResponse(NagerFixtures::response(['date' => '2027-01-01'])),
        ]);
        $provider = new CachedHolidayProvider($this->client, new ArrayAdapter());

        self::assertTrue($provider->isPublicHoliday('2026-10-06', 'LT'));
        self::assertFalse($provider->isPublicHoliday('2026-10-07', 'LT'));
        self::assertSame(1, $this->http->getRequestsCount());

        self::assertFalse($provider->isPublicHoliday('2027-10-06', 'LT'));
        self::assertSame(2, $this->http->getRequestsCount());
    }

    #[DataProvider('failedResponses')]
    public function testDoesNotCacheFailedVerificationAndAllowsRecovery(string $body, int $status): void
    {
        $this->http->setResponseFactory([
            new MockResponse($body, ['http_code' => $status]),
            new MockResponse(NagerFixtures::response()),
        ]);
        $provider = new CachedHolidayProvider($this->client, new ArrayAdapter());

        try {
            $provider->isPublicHoliday('2026-10-06', 'LT');
            self::fail('Unverified holiday data was accepted.');
        } catch (HolidayDataUnavailable) {
            self::assertSame(1, $this->http->getRequestsCount());
        }

        self::assertTrue($provider->isPublicHoliday('2026-10-06', 'LT'));
        self::assertSame(2, $this->http->getRequestsCount());
    }

    /** @return iterable<string, array{string, int}> */
    public static function failedResponses(): iterable
    {
        yield 'HTTP failure' => ['[]', 503];
        yield 'partially valid list' => [
            json_encode([NagerFixtures::holiday(), ['date' => '2026-10-07']], JSON_THROW_ON_ERROR),
            200,
        ];
    }

    public function testFilesystemCacheSurvivesProviderRecreationAndUpstreamFailure(): void
    {
        $directory = dirname(__DIR__, 2).'/var/holiday-cache-'.bin2hex(random_bytes(6));
        $this->http->setResponseFactory([
            new MockResponse(NagerFixtures::response()),
            new MockResponse('[]', ['http_code' => 503]),
        ]);

        try {
            $provider = new CachedHolidayProvider($this->client, new FilesystemAdapter(directory: $directory));
            self::assertTrue($provider->isPublicHoliday('2026-10-06', 'LT'));

            $provider = new CachedHolidayProvider($this->client, new FilesystemAdapter(directory: $directory));
            self::assertTrue($provider->isPublicHoliday('2026-10-06', 'LT'));
            self::assertFalse($provider->isPublicHoliday('2026-10-07', 'LT'));
            self::assertSame(1, $this->http->getRequestsCount());

            try {
                $provider->isPublicHoliday('2027-10-06', 'LT');
                self::fail('An uncached year must require successful holiday verification.');
            } catch (HolidayDataUnavailable) {
                self::assertTrue($provider->isPublicHoliday('2026-10-06', 'LT'));
                self::assertSame(2, $this->http->getRequestsCount());
            }
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    public function testExpiredCacheRequiresSuccessfulRefresh(): void
    {
        $this->http->setResponseFactory([
            new MockResponse(NagerFixtures::response()),
            new MockResponse('[]', ['http_code' => 503]),
        ]);
        $cache = new ArrayAdapter();
        $provider = new CachedHolidayProvider($this->client, $cache);
        self::assertTrue($provider->isPublicHoliday('2026-10-06', 'LT'));

        $keys = array_keys($cache->getValues());
        self::assertCount(1, $keys);
        $item = $cache->getItem($keys[0]);
        $item->expiresAfter(-1);
        self::assertTrue($cache->save($item));
        $this->expectException(HolidayDataUnavailable::class);

        $provider->isPublicHoliday('2026-10-06', 'LT');
    }
}
