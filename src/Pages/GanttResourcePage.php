<?php

namespace Packstub\Gantt\Pages;

use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Packstub\Gantt\Concerns\InteractsWithGantt;

/**
 * A resource page holding one chart, next to the resource's list (`/tasks/gantt`).
 * Access follows the resource's policy (`viewAny`). Implement gantt(Gantt $gantt).
 */
abstract class GanttResourcePage extends Page
{
    use InteractsWithGantt;

    protected string $view = 'packstub-gantt::pages.gantt';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @param  array<string, mixed>  $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        return static::getResource()::canAccess() && parent::canAccess($parameters);
    }
}
