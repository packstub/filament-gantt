<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Packstub\Gantt\Gantt;

/** TaskGantt with parent tasks as rows: a task without a parent is a row, its children are the bars. */
class ParentGantt extends TaskGantt
{
    public function gantt(Gantt $gantt): Gantt
    {
        return parent::gantt($gantt)->rowBy('parent');
    }
}
