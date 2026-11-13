<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Packstub\Gantt\Enums\ParentSpan;
use Packstub\Gantt\Gantt;

/** TaskGantt with parent tasks as rows: a task without a parent is a row, its children are the bars. */
class ParentGantt extends TaskGantt
{
    public static ParentSpan $span = ParentSpan::Own;

    public static bool $together = true;

    public static bool $inside = false;

    public static bool $write = false;

    public function gantt(Gantt $gantt): Gantt
    {
        return parent::gantt($gantt)
            ->rowBy('parent')
            ->parentSpan(static::$span)
            ->moveChildrenWithParent(static::$together)
            ->keepInsideParent(fn () => static::$inside)
            ->writeSpanToParent(static::$write);
    }
}
