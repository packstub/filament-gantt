<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Packstub\Gantt\Enums\Scale;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Pages\GanttPage;

/** A standalone page at /admin/planning. */
class PlanningPage extends GanttPage
{
    protected static ?string $slug = 'planning';

    public function gantt(Gantt $gantt): Gantt
    {
        return $gantt
            ->query(fn () => Task::query())
            ->start('starts_on')->end('ends_on')
            ->label('title')
            ->scale(Scale::Month);
    }
}
