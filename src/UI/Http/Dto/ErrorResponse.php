<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final readonly class ErrorResponse
{
    public function __construct(public ErrorDetails $error)
    {
    }
}
