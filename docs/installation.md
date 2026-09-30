# Installation

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- Filament 4.13 or newer, or Filament 5

## 1. Require the package

```bash
composer require packstub/filament-gantt
php artisan filament:assets
```

There are no migrations: the chart reads and writes your own columns. The script and the stylesheet load only on pages that show a chart. Run `php artisan filament:assets` again after every update of the package: Filament copies the assets into `public/`.

The config file is optional; publish it to change the defaults (scale, weekend days, pixels per day, window bounds, date formats):

```bash
php artisan vendor:publish --tag=filament-gantt-config
```

Registering the plugin on a panel is optional too (the pages and the widget work without it): `->plugin(GanttPlugin::make())`.

## 2. Add a chart page

A chart lives on a page. `GanttResourcePage` sits next to a resource's list (its access follows the resource's policy, `viewAny`); `GanttPage` is a standalone panel page. Both ask for one method, `gantt()`:

```php
namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use Filament\Actions\EditAction;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Pages\GanttResourcePage;

class TasksGantt extends GanttResourcePage
{
    protected static string $resource = TaskResource::class;

    public function gantt(Gantt $gantt): Gantt
    {
        return $gantt
            ->query(fn () => Task::query()->with('project'))
            ->start('starts_on')->end('ends_on')
            ->label('title')
            ->color(fn (Task $task) => $task->priority->getColor())
            ->progress('progress')
            ->groupBy('project', label: 'project.name')
            ->barAction(EditAction::make()->schema(TaskResource::fields()));
    }
}
```

`TaskResource::fields()` is your own helper returning the resource's form components (`->schema(fn (Schema $schema) => TaskResource::form($schema))` reuses the resource's `form()` instead).

Register it in the resource like any other page:

```php
public static function getPages(): array
{
    return [
        'index' => ListTasks::route('/'),
        'gantt' => TasksGantt::route('/gantt'),
    ];
}
```

A standalone `GanttPage` is discovered with your other pages. It is a normal Filament page, so `getHeaderActions()`, navigation and authorization work as usual.

## 3. The query decides what is on the chart

`query()` is the single source of records. Every read (bars, groups, the default window) and every write (moves, bar actions, creates) goes through it, so scope it there: a tenant, a team, the current user's projects.

```php
->query(fn () => Task::query()->whereBelongsTo(Filament::getTenant())->with(['project', 'assignee']))
```

A bar that falls out of the query (someone else archived it, the user lost access) can no longer be moved or acted on: the server answers "This bar is no longer on the chart".

## Dates

`start()` and `end()` name two attributes on the model, `starts_at` and `ends_at` by default. They can be:

- `date` casts (whole days): the end day is part of the bar, so a task from the 5th to the 7th is three days long.
- `datetime` casts (moments): the bar ends at the moment the end says; use `->snapTo(Unit::Hour)` to drag by the hour.
- plain strings: a `Y-m-d` value counts as a whole day, anything longer as a moment. `->dateOnly()` decides explicitly.

A null end makes a [milestone](bars.md#milestones).

## A chart in any Livewire component

The pages are thin: the chart itself lives in the `InteractsWithGantt` trait. Put it on any Livewire component and include the view:

```php
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Packstub\Gantt\Concerns\InteractsWithGantt;
use Packstub\Gantt\Gantt;

class ProjectTimeline extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithGantt;
    use InteractsWithSchemas;

    public Project $project;

    public function gantt(Gantt $gantt): Gantt
    {
        return $gantt->query(fn () => $this->project->tasks()->getQuery())->start('starts_on')->end('ends_on')->label('title');
    }

    public function render()
    {
        return view('livewire.project-timeline'); // @include('packstub-gantt::gantt') + <x-filament-actions::modals />
    }
}
```

`HasActions` and `HasSchemas` are only needed for [bar and create actions](actions.md).
