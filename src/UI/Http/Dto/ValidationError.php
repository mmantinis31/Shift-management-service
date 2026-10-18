<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final readonly class ValidationError
{
    public function __construct(
        public string $field,
        public string $message,
    ) {
    }
}
