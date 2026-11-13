<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Closure;
use Packstub\Gantt\Gantt;

/** TaskGantt with split tasks: `parent_id` puts a task on another task's line. */
class LineGantt extends TaskGantt
{
    public static bool $preventOverlap = false;

    public static bool $alone = false;

    public static ?Closure $canMove = null;

    public function gantt(Gantt $gantt): Gantt
    {
        return parent::gantt($gantt)
            ->lineBy('parent_id')
            ->preventLineOverlap(fn () => static::$preventOverlap)
            ->movePiecesAlone(static::$alone)
            ->canMove(static::$canMove);
    }
}
