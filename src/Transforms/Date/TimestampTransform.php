<?php

namespace InFlow\Transforms\Date;

use Carbon\Carbon;
use InFlow\Contracts\TransformStepInterface;

/**
 * Convert date/datetime to Unix timestamp
 */
class TimestampTransform implements TransformStepInterface
{
    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Carbon::parse($value)->timestamp;
        } catch (\Exception $e) {
            \inflow_report($e, 'debug', ['operation' => 'timestamp', 'value' => $value]);

            return $value;
        }
    }

    public function getName(): string
    {
        return 'timestamp';
    }
}
