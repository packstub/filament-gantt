<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Packstub\Gantt\Gantt;
use Packstub\Gantt\Widgets\GanttWidget;

/** A dashboard widget: read-only unless the test says otherwise. */
class PlanningWidget extends GanttWidget
{
    protected static ?string $heading = 'Planning';

    public static bool $editable = false;

    public function gantt(Gantt $gantt): Gantt
    {
        return $gantt
            ->query(fn () => Task::query())
            ->start('starts_on')->end('ends_on')
            ->label('title')
            ->when(static::$editable, fn (Gantt $g) => $g->readOnly(false));
    }
}
