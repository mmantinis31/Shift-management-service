<?php

declare(strict_types=1);

namespace App\Adapter\Client\Nager;

use App\Adapter\Client\Nager\Dto\HolidayResponse;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class HolidayResponseMapper
{
    public function __construct(
        private DecoderInterface $decoder,
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return list<HolidayResponse> */
    public function fromJson(string $json, string $countryCode, int $year): array
    {
        try {
            // Preserve JSON objects so {} cannot silently become a valid empty holiday list.
            $records = $this->decoder->decode($json, 'json', [JsonDecode::ASSOCIATIVE => false]);

            if (!is_array($records) || !array_is_list($records)) {
                throw new \UnexpectedValueException('Holiday response must be a list.');
            }

            if ([] === $records) {
                throw new \UnexpectedValueException('An empty annual holiday list is not reliable for Lithuania.');
            }

            $holidays = [];

            foreach ($records as $record) {
                if (!$record instanceof \stdClass) {
                    throw new \UnexpectedValueException('Each holiday record must be a JSON object.');
                }

                $holiday = $this->denormalizer->denormalize(get_object_vars($record), HolidayResponse::class, context: [
                    AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true,
                    AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => false,
                ]);

                $this->validate($holiday, $countryCode, $year);
                $holidays[] = $holiday;
            }

            return $holidays;
        } catch (ExceptionInterface $exception) {
            throw new \UnexpectedValueException('Holiday response could not be deserialized.', previous: $exception);
        }
    }

    private function validate(HolidayResponse $holiday, string $countryCode, int $year): void
    {
        $violations = $this->validator->validate($holiday);

        if (count($violations) > 0) {
            $failure = new ValidationFailedException($holiday, $violations);
            throw new \UnexpectedValueException('Holiday response contains invalid data.', previous: $failure);
        }

        if ($holiday->countryCode !== $countryCode || (int) substr($holiday->date, 0, 4) !== $year) {
            throw new \UnexpectedValueException('Holiday response does not match the requested country and year.');
        }
    }
}
