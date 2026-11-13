<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Pages\GanttPage;

/**
 * The page the browser tests open, at /admin/chart: tasks grouped by project, with
 * edit, delete and create actions. A test shapes it further with `$define`
 * (the browser's server runs in the test's own process, so a static reaches it).
 */
class ChartPage extends GanttPage
{
    protected static ?string $slug = 'chart';

    /** @var Closure|null `fn (Gantt): Gantt` */
    public static ?Closure $define = null;

    public function gantt(Gantt $gantt): Gantt
    {
        $fields = [TextInput::make('title')->required(), DatePicker::make('starts_on')->native(), DatePicker::make('ends_on')->native()];

        $gantt
            ->query(fn () => Task::query()->with('project'))
            ->start('starts_on')->end('ends_on')
            ->label('title')
            ->groupBy('project', label: 'project.name')
            ->barActions([EditAction::make()->schema($fields), DeleteAction::make()])
            ->barAction('edit')
            ->createAction(CreateAction::make()->schema($fields));

        return static::$define ? (static::$define)($gantt) : $gantt;
    }
}
