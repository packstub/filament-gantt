# Filament Gantt

<div class="filament-hidden">

![Filament Gantt: a fast Gantt chart and resource timeline for Filament](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-gantt.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-gantt)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-gantt/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-gantt/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/filament-gantt.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-gantt)
[![License](https://img.shields.io/packagist/l/packstub/filament-gantt.svg?style=flat-square)](https://github.com/packstub/filament-gantt/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

A Gantt chart and resource timeline for Filament: any Eloquent query with a start and an end becomes draggable bars on a day, week or month axis, grouped by project, assignee, room or machine, with every rule enforced on the server. The sibling of [Filament Kanban](https://github.com/packstub/filament-kanban).

## Features

- **[Drag to move, drag an edge to resize](#moving-and-resizing)**: the bar moves at once, snapped to the day (or the hour), and the server is asked afterwards. If it says no, the bar slides back and the reason is shown. Nothing re-renders on a move.
- **[Rules on the server](#moving-and-resizing)**: `movable()`, `resizable()`, `minDuration()`, `maxDuration()`, `lockedBefore(today())` and a `canMove()` closure, all checked again in `Gantt::move()` whatever the browser sent.
- **[Groups and swimlanes](#groups-and-resource-timelines)**: rows grouped by a column or a relation, collapsible, with a count; or the compact layout where a room's bookings share one row and overlapping ones stack.
- **[Milestones and progress](#defining-bars)**: a null end is a diamond; `progress('progress')` fills the bar.
- **[Filament actions on bars](#actions)**: click a bar to edit it in a modal or slide-over, right-click for more; click an empty spot to create a record with that date and group already filled in.
- **[Three scales, today line, weekends](#the-time-window)**: day, week and month, a two-row axis, a date-range picker and select or date-range filters, all kept in the URL.
- **[Resource page, standalone page or dashboard widget](#installation)**: `GanttResourcePage` (`/tasks/gantt`, follows the resource policy), `GanttPage`, and `GanttWidget` (read-only by default).
- **Events**: a `BarMoved` Laravel event, an `afterMove()` hook, a browser event, `refreshOn([...])` for Livewire events and optional polling.
- **No Gantt library, no build step**: one hand-written ES module and a stylesheet on your panel's palette. **Dark mode**, **RTL** and **translatable** (English, Romanian, Russian, German). MIT.

## Compatibility

| Plugin | Filament | Laravel | PHP |
| --- | --- | --- | --- |
| 1.x | 4.13+, 5.x | 12.x, 13.x | 8.3+ |

## Installation

```bash
composer require packstub/filament-gantt
php artisan filament:assets
```

No migrations: the chart reads and writes your own start and end columns (`date` or `datetime` casts, or plain strings). The config file is optional (`php artisan vendor:publish --tag=packstub-gantt-config`).

A chart in thirty seconds, next to a resource:

```php
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
            ->movable(fn (Task $task) => $task->status !== 'done')
            ->lockedBefore(today())
            ->barAction(EditAction::make()->schema(TaskResource::fields()));
    }
}
```

Register it in the resource's `getPages()`: `'gantt' => TasksGantt::route('/gantt')`. A standalone `GanttPage` and a `GanttWidget` ask for the same `gantt()` method. (`TaskResource::fields()` is your own helper returning the form components; `->schema(fn (Schema $schema) => TaskResource::form($schema))` reuses the resource's form.)

Read more: [Installation](https://packstub.dev/docs/filament-gantt/installation).

## Defining bars

![TODO screenshot: a project timeline at week scale with groups, a milestone and progress fills](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/gantt.png)

`start()` and `end()` name the attributes; `label()`, `color()` and `progress()` take an attribute name or a closure. Whole-day records (a `date` cast) include their end day in the bar; datetime records end at the moment they say. A null end is a milestone. For anything else, shape the bar in one closure:

```php
->bar(fn (Task $task) => Bar::make()
    ->label($task->title)
    ->color($task->is_late ? 'danger' : 'sky')
    ->progress($task->progress)
    ->tooltip($task->assignee?->name)
    ->actions($task->is_locked ? ['view'] : ['view', 'edit', 'delete']))
```

Read more: [Bars](https://packstub.dev/docs/filament-gantt/bars).

## Groups and resource timelines

Group rows by a column or a `BelongsTo` relation; each group is a collapsible row with a count. For a **resource timeline** (rooms × bookings, machines × jobs) list the resources yourself so empty ones appear, and use the compact layout:

```php
->groupBy('room', label: 'room.name')
->groups(fn () => Group::fromModels(Room::query()->orderBy('name')->get()))
->compact()
```

Read more: [Groups](https://packstub.dev/docs/filament-gantt/groups).

## Moving and resizing

![TODO screenshot: dragging a bar, the date hint under the pointer](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/drag.png)

A drag changes the bar in the browser and sends `ganttMove(id, start, end)`, a renderless Livewire call. The server loads the record through the chart's `query()`, snaps the dates, checks every rule and saves; the bar flashes when it is saved or slides back with the reason when it is refused.

```php
->snapTo(Unit::Day)                     // or Unit::Hour for datetime records
->movable(fn (Task $task) => $task->status !== 'done')
->resizable(true)
->minDuration(days: 1)->maxDuration(days: 30)
->lockedBefore(today())                 // the past stays as it happened
->canMove(fn (Task $task, CarbonImmutable $start) => $start->isWeekend() ? 'Not on a weekend.' : true)
->afterMove(fn (Task $task, CarbonImmutable $start, CarbonImmutable $end) => $task->touch())
```

Give your own persistence to `moveUsing()` and throw `MoveRejected` to refuse with a message (any other exception is reported and the user sees a generic one). `Packstub\Gantt\Events\BarMoved` fires after every saved move. The chart's own maths is on wall-clock moments tagged UTC, so a daylight-saving change never lengthens a bar; your closures and the event get the same moments in the app's timezone, so `$start->isPast()` reads naturally. Escape abandons a drag.

Read more: [Moving and resizing](https://packstub.dev/docs/filament-gantt/moves).

## The time window

Day, week and month scales, a today line, weekend shading, a date-range picker and filters in the toolbar. The window defaults to a week before the earliest bar and two weeks after the latest, or set it with `startDate()`/`endDate()`. Filters are applied on the server:

```php
->scale(Scale::Week)
->filters([
    Filter::select('assignee_id')->options(fn () => User::pluck('name', 'id')->all()),
    Filter::dateRange('starts_on'),
])
```

Read more: [Filters and the time window](https://packstub.dev/docs/filament-gantt/filters).

## Actions

![TODO screenshot: editing a task in a slide-over opened by clicking its bar](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/edit.png)

Your Filament actions work on bars: `barAction(EditAction::make()->schema([...]))` opens on click, `barActions([...])` fills the right-click menu, and `createAction(CreateAction::make()->schema([...]))` opens the create form with the clicked spot's start, end and group filled in. Records are resolved through the chart's query, so an action can never reach a record the user cannot see.

Read more: [Actions](https://packstub.dev/docs/filament-gantt/actions).

## Widget and read-only mode

`GanttWidget` puts a chart on the dashboard, read-only by default; `->readOnly()` on any chart shows it without drag handles or create clicks, and the server refuses moves too.

Read more: [Widget and read-only mode](https://packstub.dev/docs/filament-gantt/widget).

## Kanban + Gantt on the same records

Both plugins take the same `query()` and the same Filament actions, so a `TaskBoard` and a `TasksGantt` page can sit next to each other in one resource, one for the flow, one for the schedule. A `BarMoved` listener can update the status and a `CardMoved` listener the dates.

Read more: [Kanban + Gantt](https://packstub.dev/docs/filament-gantt/kanban).

## Configuration

```php
$gantt
    ->scale('week')            // day | week | month; the user can change it
    ->snapTo('day')            // day | hour
    ->firstDayOfWeek(1)        // 1 = Monday … 7 = Sunday
    ->startDate('2026-01-01')->endDate('2026-12-31')
    ->refreshOn(['task-updated'])
    ->poll('30s')
    ->key('tasks');            // where the browser remembers the zoom and folded groups
```

`config/filament-gantt.php` holds the defaults: scale, first day of week, weekend days, row height, pixels per day per scale, window padding and bounds, polling and the date formats of tooltips.

![TODO screenshot: the chart in dark mode](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/dark.png)

The stylesheet is plain CSS on Filament's colour variables and follows dark mode; it uses logical properties, so it reads right-to-left with the panel. Restyle it with the `--ps-gantt-*` variables on `.ps-gantt` from your theme.

Read more: [Styling](https://packstub.dev/docs/filament-gantt/styling).

## Documentation

- [Installation](https://packstub.dev/docs/filament-gantt/installation)
- [Bars](https://packstub.dev/docs/filament-gantt/bars)
- [Groups](https://packstub.dev/docs/filament-gantt/groups)
- [Moving and resizing](https://packstub.dev/docs/filament-gantt/moves)
- [Filters and the time window](https://packstub.dev/docs/filament-gantt/filters)
- [Actions](https://packstub.dev/docs/filament-gantt/actions)
- [Widget and read-only mode](https://packstub.dev/docs/filament-gantt/widget)
- [Styling](https://packstub.dev/docs/filament-gantt/styling)
- [Performance](https://packstub.dev/docs/filament-gantt/performance)
- [Kanban + Gantt](https://packstub.dev/docs/filament-gantt/kanban)
- [Upgrading](https://packstub.dev/docs/filament-gantt/upgrading)

The same pages live in the [`docs/`](https://github.com/packstub/filament-gantt/tree/main/docs) directory of this repository.

## Testing

```bash
composer test      # the Pest suite
composer test:js   # the browser side (layout, drag, menu) on plain Node, no packages
```

## Changelog

See the [changelog](https://github.com/packstub/filament-gantt/blob/main/CHANGELOG.md).

## Security vulnerabilities

Please e-mail [support@packstub.dev](mailto:support@packstub.dev) rather than opening a public issue.

## Credits

- [Ion Caliman](https://github.com/icaliman)
- [All contributors](https://github.com/packstub/filament-gantt/contributors)

## License

MIT. See the [license file](https://github.com/packstub/filament-gantt/blob/main/LICENSE.md).
