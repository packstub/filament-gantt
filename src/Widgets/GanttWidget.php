<?php

namespace Packstub\Gantt\Widgets;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Packstub\Gantt\Concerns\InteractsWithGantt;
use Packstub\Gantt\Gantt;

/**
 * A dashboard widget holding one chart, read-only unless gantt() says
 * `->readOnly(false)`. Implement gantt(Gantt $gantt).
 */
abstract class GanttWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithGantt;
    use InteractsWithSchemas;

    protected string $view = 'packstub-gantt::widgets.gantt';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = null;

    /** The chart's height inside the widget (a CSS length). */
    protected string $height = '24rem';

    protected function makeGantt(): Gantt
    {
        return Gantt::make()->key(static::class)->readOnly();
    }

    public function getHeading(): ?string
    {
        return static::$heading;
    }

    protected function getGanttHeight(): ?string
    {
        return $this->height;
    }
}
