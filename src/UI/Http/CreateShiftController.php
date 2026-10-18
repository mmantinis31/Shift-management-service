<?php

declare(strict_types=1);

namespace App\UI\Http;

use App\Application\CreateShift\CreateShiftHandler;
use App\UI\Http\Dto\CreateShiftRequest;
use App\UI\Http\Dto\ShiftResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class CreateShiftController
{
    private const string PATH = '/shifts';
    private const string ROUTE_NAME = 'create_shift';

    public function __construct(
        private CreateShiftHandler $handler,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route(
        self::PATH,
        name: self::ROUTE_NAME,
        methods: [Request::METHOD_POST],
        format: 'json',
    )]
    public function __invoke(
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            validationFailedStatusCode: Response::HTTP_BAD_REQUEST,
        )]
        CreateShiftRequest $request,
    ): JsonResponse {
        $shift = ($this->handler)($request->toCommand());

        return JsonResponse::fromJsonString(
            $this->serializer->serialize(ShiftResponse::fromShift($shift), 'json'),
            Response::HTTP_CREATED,
        );
    }
}
