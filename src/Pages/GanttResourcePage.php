<?php

namespace Packstub\Gantt\Pages;

use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Packstub\Gantt\Concerns\InteractsWithGantt;
use Packstub\Gantt\Gantt;

/**
 * A resource page holding one chart, next to the resource's list (`/tasks/gantt`).
 * It follows the resource's policy: the page needs `viewAny`, a move needs the
 * resource's canEdit() (`update`), and the bar and create actions are offered
 * only to who may run them. Implement gantt(Gantt $gantt).
 */
abstract class GanttResourcePage extends Page
{
    use InteractsWithGantt;

    protected string $view = 'packstub-gantt::pages.gantt';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function makeGantt(): Gantt
    {
        $resource = static::getResource();

        return Gantt::make()->key(static::class)->authorizeMoves(fn (Model $record): bool => $resource::canEdit($record));
    }

    /** @param  array<string, mixed>  $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        return static::getResource()::canAccess() && parent::canAccess($parameters);
    }
}
