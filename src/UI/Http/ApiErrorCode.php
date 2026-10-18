<?php

declare(strict_types=1);

namespace App\UI\Http;

enum ApiErrorCode: string
{
    case InvalidJson = 'invalid_json';
    case InvalidInput = 'invalid_input';
    case OverlappingShift = 'overlapping_shift';
    case PublicHoliday = 'public_holiday';
    case ShiftRuleViolation = 'shift_rule_violation';
    case HolidayDataUnavailable = 'holiday_data_unavailable';
    case StorageBusy = 'storage_busy';
    case HttpError = 'http_error';
    case InternalError = 'internal_error';
}
