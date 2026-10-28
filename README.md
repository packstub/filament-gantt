# Filament Gantt

<div class="filament-hidden">

![Filament Gantt: a fast Gantt chart and resource timeline for Filament](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-gantt.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-gantt)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-gantt/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-gantt/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/filament-gantt.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-gantt)
[![License](https://img.shields.io/packagist/l/packstub/filament-gantt.svg?style=flat-square)](https://github.com/packstub/filament-gantt/blob/main/LICENSE.md)
[![Sponsor](https://img.shields.io/badge/sponsor-%E2%9D%A4-ea4aaa?style=flat-square&logo=githubsponsors&logoColor=white)](https://github.com/sponsors/icaliman)

</div>

A Gantt chart and resource timeline for Filament: any Eloquent query with a start and an end becomes draggable bars on an hour, day, week, month or year axis, grouped by project, assignee, room or machine, with every rule enforced on the server. The sibling of [Filament Kanban](https://github.com/packstub/filament-kanban).

## Features

- **[Drag to move, resize or reassign](#moving-and-resizing)**: bars move at once, by an edge or to another row.
- **[Rules on the server](#moving-and-resizing)**: policies, durations and a locked past, always checked.
- **[Groups and resource timelines](#groups-and-resource-timelines)**: rows by project, assignee, room or machine.
- **[Milestones, progress and dependencies](#defining-bars)**: diamonds, fills and finish-to-start arrows.
- **[Filament actions on bars](#actions)**: click to edit, right-click for more, click to create.
- **[Hour to year, zoom and Fit](#the-time-window)**: five scales, wheel or pinch zoom, the whole plan in one screen.
- **[Search and filters](#the-time-window)**: Filament's filter fields, kept in the URL.
- **[Keyboard and touch](https://packstub.dev/docs/filament-gantt/moves)**: move and resize with the arrows, drag on touch.
- **[Resource page, standalone page or widget](#installation)**: wherever your panel needs a timeline.
- **[No Gantt library, no build step](#configuration)**: dark mode, RTL and four languages.

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

![A project timeline at week scale: two project groups, progress fills, dependency arrows and the today line](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/gantt.png)

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

![A resource timeline on the hour scale: one row per member, the shifts of a member side by side, a line at now](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/shifts.png)

A bar dragged up or down lands in the group under the pointer (a booking in another room), within `regroupable()` and `canRegroup()`:

![A bar carried onto another project's rows: the target group lit, the hint naming it](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/row-drag.png)

Read more: [Groups](https://packstub.dev/docs/filament-gantt/groups).

## Moving and resizing

![Dragging a bar: the date hint under the pointer, the arrows following it](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/drag.png)

A drag changes the bar in the browser and sends `ganttMove(id, start, end, group)`, a renderless Livewire call (the group only when the bar was dragged to another row). The server loads the record through the chart's `query()`, snaps the dates, checks every rule and saves; the bar flashes when it is saved or slides back with the reason when it is refused.

```php
->snapTo(Unit::Day)                     // or Unit::Hour for datetime records
->authorizeMoves(fn (Task $task) => auth()->user()->can('update', $task))   // the default when Task has a policy
->movable(fn (Task $task) => $task->status !== 'done')
->resizable(true)
->regroupable(fn (Task $task) => $task->status !== 'done')   // may it be dragged to another row (project)?
->minDuration(days: 1)->maxDuration(days: 30)
->lockedBefore(today())                 // the past stays as it happened
->canMove(fn (Task $task, CarbonImmutable $start) => $start->isWeekend() ? 'Not on a weekend.' : true)
->afterMove(fn (Task $task, CarbonImmutable $start, CarbonImmutable $end) => $task->touch())
```

Give your own persistence to `moveUsing()` and throw `MoveRejected` to refuse with a message (any other exception is reported and the user sees a generic one). `Packstub\Gantt\Events\BarMoved` fires after every saved move. The chart's own maths is on wall-clock moments tagged UTC, so a daylight-saving change never lengthens a bar; your closures and the event get the same moments in the app's timezone, so `$start->isPast()` reads naturally. Escape abandons a drag. On a focused bar, ←/→ move it, ↑/↓ carry it to another row, Shift+←/→ move its end and Alt+←/→ its start.

Read more: [Moving and resizing](https://packstub.dev/docs/filament-gantt/moves).

## The time window

Hour, day, week, month and year scales, a today line, weekend shading, a date-range picker, a search box and filters in a table-style toolbar. The window defaults to a week before the earliest bar and two weeks after the latest (around today for a history longer than two years), or set it with `startDate()`/`endDate()`. The search and the filters are applied on the server; the filters sit behind a funnel button by default, inline or above the chart with `filtersLayout()`:

```php
->scale(Scale::Week)
->searchable(['title', 'project.name'])
->filters([
    Filter::select('assignee_id')->options(fn () => User::pluck('name', 'id')->all()),
    Filter::select('tags')->multiple()->options(['bug' => 'Bug', 'feature' => 'Feature']),
    Filter::boolean('is_billable'),
    Filter::toggle('mine')->query(fn ($query) => $query->where('assignee_id', auth()->id())),
    Filter::dateRange('starts_on'),
])
->filtersLayout(FiltersLayout::Toolbar)
```

The year scale fits a plan of two years in one screen, quarters over months:

![The year scale: quarters over months](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/year.png)

Read more: [Filters and the time window](https://packstub.dev/docs/filament-gantt/filters).

## Actions

![Editing a task in a modal opened by clicking its bar](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/edit.png)

Your Filament actions work on bars: `barAction(EditAction::make()->schema([...]))` opens on click, `barActions([...])` fills the right-click menu, and `createAction(CreateAction::make()->schema([...]))` opens the create form with the clicked spot's start, end and group filled in. Records are resolved through the chart's query, so an action can never reach a record the user cannot see, and each bar offers only the actions the user may run on its record (on a resource page, the resource's policy).

Read more: [Actions](https://packstub.dev/docs/filament-gantt/actions).

## Widget and read-only mode

`GanttWidget` puts a chart on the dashboard, read-only by default; `->readOnly()` on any chart shows it without drag handles or create clicks, and the server refuses moves too.

Read more: [Widget and read-only mode](https://packstub.dev/docs/filament-gantt/widget).

## Kanban + Gantt on the same records

Both plugins take the same `query()` and the same Filament actions, so a `TaskBoard` and a `TasksGantt` page can sit next to each other in one resource, one for the flow, one for the schedule. A `BarMoved` listener can update the status and a `CardMoved` listener the dates.

![The same tasks on a Kanban board](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/kanban.png)

Read more: [Kanban + Gantt](https://packstub.dev/docs/filament-gantt/kanban).

## Configuration

```php
$gantt
    ->scale('week')            // hour | day | week | month | year; the user can change it
    ->scales(['day', 'week'])  // the zoom levels offered
    ->snapTo('day')            // day | hour
    ->firstDayOfWeek(1)        // 1 = Monday … 7 = Sunday
    ->startDate('2026-01-01')->endDate('2026-12-31')
    ->refreshOn(['task-updated'])
    ->poll('30s')
    ->key('tasks');            // where the browser remembers the zoom and folded groups
```

`config/filament-gantt.php` holds the defaults: scale, first day of week, weekend days, row height, pixels per day per scale, window padding and bounds, `max_bars`, polling, where the filters are shown (`filters_layout`) and the date formats of tooltips (the locale's by default).

![The chart in dark mode](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/dark.png)

The chart sits in a table-style container whose toolbar, filters and menus are Filament's own components, so the panel's theme styles them. The stylesheet is plain CSS on Filament's colour variables and follows dark mode; it uses logical properties, so it reads right-to-left with the panel. Restyle it with the `--ps-gantt-*` variables on `.ps-gantt` from your theme (the same scheme as Kanban's `--ps-kanban-*` and Spreadsheet's `--ps-sheet-*`):

```css
.fi-body .ps-gantt {
    --ps-gantt-label-width: 18rem;
    --ps-gantt-today: var(--danger-500);
}
```

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
