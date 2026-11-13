<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Closure;
use Packstub\Gantt\Gantt;

/** TaskGantt with rows that hold several bars: one row per assignee, under each project. */
class RowGantt extends TaskGantt
{
    public static bool $preventOverlap = false;

    /** @var Closure|null `fn (): list<Row>` */
    public static ?Closure $rows = null;

    public static ?Closure $creatableInRow = null;

    public function gantt(Gantt $gantt): Gantt
    {
        return parent::gantt($gantt)
            ->query(fn () => Task::query()->with(['project', 'assignee']))
            ->rowBy('assignee', label: 'assignee.name')
            ->rows(static::$rows)
            ->preventRowOverlap(fn () => static::$preventOverlap)
            ->creatableInRow(static::$creatableInRow);
    }
}
