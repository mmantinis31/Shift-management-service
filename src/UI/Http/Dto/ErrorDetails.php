<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

use App\UI\Http\ApiErrorCode;

final readonly class ErrorDetails
{
    /** @param list<ValidationError> $details */
    public function __construct(
        public ApiErrorCode $code,
        public string $message,
        public array $details = [],
    ) {
    }
}
