<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Packstub\Gantt\Concerns\InteractsWithGantt;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Gantt;

/** A plain Livewire component holding a chart, the way an app page would. */
class TaskGantt extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithGantt;
    use InteractsWithSchemas;

    /** Titles that may be moved; null = all. */
    public static ?array $movable = null;

    public static bool $readOnly = false;

    /** @var list<array<int, mixed>> what afterMove() received */
    public static array $moved = [];

    public function gantt(Gantt $gantt): Gantt
    {
        return $gantt
            ->query(fn () => Task::query()->with('project'))
            ->start('starts_on')->end('ends_on')
            ->label('title')
            ->progress('progress')
            ->groupBy('project', label: 'project.name')
            ->filters([
                Filter::select('project_id')->options(fn () => Project::query()->pluck('name', 'id')->all()),
                Filter::dateRange('starts_on'),
            ])
            ->movable(fn (Task $task) => static::$movable === null || in_array($task->title, static::$movable, true))
            ->readOnly(fn () => static::$readOnly)
            ->minDuration(days: 1)
            ->maxDuration(days: 30)
            ->afterMove(function (Task $task, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $previousStart, CarbonImmutable $previousEnd) {
                static::$moved[] = [$task->id, $previousStart->toDateString(), $start->toDateString()];
            })
            ->barActions([
                EditAction::make()->schema([TextInput::make('title')->required(), DatePicker::make('starts_on'), DatePicker::make('ends_on')]),
                DeleteAction::make()->hidden(fn (Task $record) => $record->progress >= 100),
                Action::make('bump')->action(fn (Task $record) => $record->increment('progress', 10)),
            ])
            ->barAction('edit')
            ->createAction(CreateAction::make()->schema([TextInput::make('title')->required(), DatePicker::make('starts_on'), DatePicker::make('ends_on')]));
    }

    public function render(): string
    {
        return '<div>@include(\'packstub-gantt::gantt\') <x-filament-actions::modals /></div>';
    }
}
