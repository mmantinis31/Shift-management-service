<?php

declare(strict_types=1);

namespace App\Adapter\Holiday;

use App\Adapter\Client\Nager\NagerHolidayClient;
use App\Application\Contract\HolidayProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class CachedHolidayProvider implements HolidayProviderInterface
{
    private const string CACHE_KEY_FORMAT = 'holidays.v3.%s.%d';
    private const int DEFAULT_TTL_SECONDS = 86400;

    public function __construct(
        private NagerHolidayClient $client,
        private CacheInterface $cache,
        private int $cacheTtl = self::DEFAULT_TTL_SECONDS,
    ) {
        if ($cacheTtl <= 0) {
            throw new \InvalidArgumentException('Holiday cache TTL must be positive.');
        }
    }

    public function isPublicHoliday(string $date, string $countryCode): bool
    {
        $year = (int) substr($date, 0, 4);
        $key = sprintf(self::CACHE_KEY_FORMAT, $countryCode, $year);

        /** @var array<string, true> $dates */
        $dates = $this->cache->get($key, function (ItemInterface $item) use ($countryCode, $year): array {
            $dates = [];

            foreach ($this->client->holidays($countryCode, $year) as $holiday) {
                if ($holiday->isPublicHoliday()) {
                    $dates[$holiday->date] = true;
                }
            }
            $item->expiresAfter($this->cacheTtl);

            return $dates;
        }, beta: 0.0);

        return isset($dates[$date]);
    }
}
