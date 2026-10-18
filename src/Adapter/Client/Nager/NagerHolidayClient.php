<?php

declare(strict_types=1);

namespace App\Adapter\Client\Nager;

use App\Adapter\Client\Nager\Dto\HolidayResponse;
use App\Application\Exception\HolidayDataUnavailable;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NagerHolidayClient
{
    private const float IDLE_TIMEOUT_SECONDS = 3.0;
    private const float MAX_DURATION_SECONDS = 5.0;
    private const int MAX_REDIRECTS = 0;

    public function __construct(private HttpClientInterface $httpClient, private HolidayResponseMapper $mapper)
    {
    }

    /**
     * @return list<HolidayResponse>
     */
    public function holidays(string $countryCode, int $year): array
    {
        try {
            $path = sprintf('Holidays/%s/%d', rawurlencode($countryCode), $year);
            $response = $this->httpClient->request('GET', $path, [
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'max_redirects' => self::MAX_REDIRECTS,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300) {
                throw new \UnexpectedValueException(sprintf('Holiday API returned HTTP %d.', $status));
            }

            return $this->mapper->fromJson($response->getContent(false), $countryCode, $year);
        } catch (TransportExceptionInterface|\UnexpectedValueException $exception) {
            throw new HolidayDataUnavailable($exception);
        }
    }
}
