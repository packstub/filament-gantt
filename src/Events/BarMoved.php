<?php

namespace Packstub\Gantt\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bar was moved or resized on a Gantt. The dates are the bar's span as the chart
 * sees it: a half-open range, so a date-only record ending on the 7th has an end of
 * the 8th at 00:00 here. A milestone has start == end.
 */
class BarMoved
{
    use Dispatchable;

    public function __construct(
        public readonly Model $record,
        public readonly CarbonImmutable $previousStart,
        public readonly CarbonImmutable $previousEnd,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?string $gantt = null,
    ) {}

    /** Whether the duration changed (a resize) rather than the position (a move). */
    public function isResize(): bool
    {
        return $this->previousStart->diffInMinutes($this->previousEnd) !== $this->start->diffInMinutes($this->end);
    }
}
