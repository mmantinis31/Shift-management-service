<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final readonly class HealthResponse
{
    public const string STATUS_OK = 'ok';

    public function __construct(
        public string $status = self::STATUS_OK,
    ) {
    }
}
