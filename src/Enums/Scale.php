<?php

namespace Packstub\Gantt\Enums;

/** How wide a day is drawn: the zoom levels of the axis. */
enum Scale: string
{
    /** One column per hour, days on top: shifts and bookings. */
    case Hour = 'hour';

    case Day = 'day';

    case Week = 'week';

    case Month = 'month';

    /** Months over quarters, about a year and a half on a wide screen: roadmaps and plans. */
    case Year = 'year';

    public static function fromName(?string $value, self $default = self::Week): self
    {
        return $value === null ? $default : (self::tryFrom(strtolower($value)) ?? $default);
    }
}
