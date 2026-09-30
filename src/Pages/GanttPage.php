<?php

namespace Packstub\Gantt\Pages;

use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Packstub\Gantt\Concerns\InteractsWithGantt;

/** A standalone panel page holding one chart. Implement gantt(Gantt $gantt). */
abstract class GanttPage extends Page
{
    use InteractsWithGantt;

    protected string $view = 'packstub-gantt::pages.gantt';

    protected Width|string|null $maxContentWidth = Width::Full;
}
