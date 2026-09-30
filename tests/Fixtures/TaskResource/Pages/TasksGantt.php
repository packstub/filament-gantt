<?php

namespace Packstub\Gantt\Tests\Fixtures\TaskResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Pages\GanttResourcePage;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskResource;

/** The resource's timeline at /admin/tasks/gantt. */
class TasksGantt extends GanttResourcePage
{
    protected static string $resource = TaskResource::class;

    public function gantt(Gantt $gantt): Gantt
    {
        $fields = [TextInput::make('title')->required(), DatePicker::make('starts_on'), DatePicker::make('ends_on')];

        return $gantt
            ->query(fn () => Task::query()->with('project'))
            ->start('starts_on')->end('ends_on')
            ->label('title')
            ->progress('progress')
            ->groupBy('project', label: 'project.name')
            ->barAction(EditAction::make()->schema($fields))
            ->createAction(CreateAction::make()->schema($fields));
    }
}
