<?php

declare(strict_types=1);

namespace App\UI\Http;

use App\Application\Exception\HolidayDataUnavailable;
use App\Application\Exception\PublicHoliday;
use App\Application\Exception\ShiftStorageBusy;
use App\Domain\Exception\InvalidShiftInput;
use App\Domain\Exception\OverlappingShift;
use App\Domain\Exception\ShiftRuleViolation;
use App\UI\Http\Dto\ErrorDetails;
use App\UI\Http\Dto\ErrorResponse;
use App\UI\Http\Dto\ValidationError;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final readonly class ApiExceptionSubscriber implements EventSubscriberInterface
{
    private const string INVALID_INPUT_MESSAGE = 'Request fields are invalid.';
    private const int RETRY_AFTER_SECONDS = 5;

    public function __construct(private LoggerInterface $logger, private NameConverterInterface $nameConverter)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        [$status, $code, $message] = $this->classify($exception);

        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            $this->logger->error('Shift API request failed.', [
                'exception' => $exception,
                'error_code' => $code->value,
            ]);
        }

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        if (Response::HTTP_SERVICE_UNAVAILABLE === $status) {
            $headers['Retry-After'] = (string) self::RETRY_AFTER_SECONDS;
        }
        $error = new ErrorDetails(
            $code,
            $message,
            Response::HTTP_BAD_REQUEST === $status ? $this->validationErrors($exception) : [],
        );
        $event->setResponse(new JsonResponse(new ErrorResponse($error), $status, $headers));
    }

    /**
     * @return array{int, ApiErrorCode, string}
     */
    private function classify(\Throwable $exception): array
    {
        $previous = $exception instanceof HttpExceptionInterface
            && Response::HTTP_BAD_REQUEST === $exception->getStatusCode()
            ? $exception->getPrevious()
            : null;

        return match (true) {
            $previous instanceof ValidationFailedException,
            $exception instanceof ExtraAttributesException,
            $exception instanceof NotNormalizableValueException => [
                Response::HTTP_BAD_REQUEST, ApiErrorCode::InvalidInput, self::INVALID_INPUT_MESSAGE,
            ],
            $previous instanceof NotEncodableValueException => [
                Response::HTTP_BAD_REQUEST, ApiErrorCode::InvalidJson, 'Request body must contain valid JSON.',
            ],
            $exception instanceof InvalidShiftInput => [
                Response::HTTP_BAD_REQUEST, ApiErrorCode::InvalidInput, $exception->getMessage(),
            ],
            $exception instanceof OverlappingShift => [
                Response::HTTP_CONFLICT, ApiErrorCode::OverlappingShift, $exception->getMessage(),
            ],
            $exception instanceof PublicHoliday => [
                Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::PublicHoliday, $exception->getMessage(),
            ],
            $exception instanceof ShiftRuleViolation => [
                Response::HTTP_UNPROCESSABLE_ENTITY, ApiErrorCode::ShiftRuleViolation, $exception->getMessage(),
            ],
            $exception instanceof HolidayDataUnavailable => [
                Response::HTTP_SERVICE_UNAVAILABLE, ApiErrorCode::HolidayDataUnavailable, $exception->getMessage(),
            ],
            $exception instanceof ShiftStorageBusy => [
                Response::HTTP_SERVICE_UNAVAILABLE, ApiErrorCode::StorageBusy, $exception->getMessage(),
            ],
            $exception instanceof HttpExceptionInterface => [
                $exception->getStatusCode(), ApiErrorCode::HttpError,
                Response::$statusTexts[$exception->getStatusCode()] ?? 'HTTP error',
            ],
            default => [
                Response::HTTP_INTERNAL_SERVER_ERROR, ApiErrorCode::InternalError,
                'An unexpected internal error occurred.',
            ],
        };
    }

    /**
     * @return list<ValidationError>
     */
    private function validationErrors(\Throwable $exception): array
    {
        $previous = $exception->getPrevious();
        $errors = [];

        if ($previous instanceof ValidationFailedException) {
            foreach ($previous->getViolations() as $violation) {
                $errors[] = new ValidationError(
                    $this->nameConverter->normalize($violation->getPropertyPath()),
                    (string) $violation->getMessage(),
                );
            }
        } elseif ($exception instanceof ExtraAttributesException) {
            foreach ($exception->getExtraAttributes() as $field) {
                $errors[] = new ValidationError($field, 'This field was not expected.');
            }
        } elseif ($exception instanceof InvalidShiftInput) {
            $errors[] = new ValidationError($exception->field, $exception->getMessage());
        }
        usort(
            $errors,
            static function (ValidationError $a, ValidationError $b): int {
                return [$a->field, $a->message] <=> [$b->field, $b->message];
            },
        );

        return $errors;
    }
}
