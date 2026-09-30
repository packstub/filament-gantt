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

    /** The moment itself snapped to the unit (a click on the axis has nothing to be relative to). */
    public function snap(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            self::Hour => $moment->startOfHour(),
            self::Day => $moment->startOfDay(),
        };
    }

    /**
     * A moment snapped relative to where it came from: the delta is rounded to whole
     * units and the time of day is kept, the way the browser drags (a shift at 08:00
     * dragged by a day is still at 08:00).
     */
    public function snapFrom(CarbonImmutable $moment, CarbonImmutable $reference): CarbonImmutable
    {
        $unit = $this->minutes();
        $delta = (int) round($reference->diffInMinutes($moment, false) / $unit) * $unit;

        return $reference->addMinutes($delta);
    }
}
