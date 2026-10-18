<?php

declare(strict_types=1);

namespace App\UI\Http;

use App\UI\Http\Dto\HealthResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    private const string PATH = '/health';
    private const string ROUTE_NAME = 'health';

    #[Route(self::PATH, name: self::ROUTE_NAME, methods: [Request::METHOD_GET], format: 'json')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(new HealthResponse());
    }
}
