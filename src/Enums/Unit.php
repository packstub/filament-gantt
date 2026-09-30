<?php

namespace Packstub\Gantt\Enums;

use Carbon\CarbonImmutable;

/** What a drag snaps to, in the browser and again on the server. */
enum Unit: string
{
    case Hour = 'hour';
    case Day = 'day';

    public function minutes(): int
    {
        return match ($this) {
            self::Hour => 60,
            self::Day => 1440,
        };
    }

    public function snap(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            self::Hour => $moment->startOfHour(),
            self::Day => $moment->startOfDay(),
        };
    }
}
