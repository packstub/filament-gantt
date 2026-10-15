# Filament Gantt

![Filament Gantt](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/banner.jpg)

A Gantt chart and resource timeline for Filament v4 (4.13+) and v5. Any Eloquent query with a start and an end becomes draggable bars on an hour, day, week, month or year axis, grouped by whatever you like; the browser owns the chart, so a drag lands at once, and the server checks every rule again in the background. Free and open source (MIT). The sibling of [Filament Kanban](https://packstub.dev/docs/filament-kanban).

- Repository: [github.com/packstub/filament-gantt](https://github.com/packstub/filament-gantt)
- Packagist: [packstub/filament-gantt](https://packagist.org/packages/packstub/filament-gantt)
- Support: [GitHub issues](https://github.com/packstub/filament-gantt/issues)

## Features

- **[Drag to move, resize or reassign](moves.md)**: a bar moves at once; drag an edge to resize, up or down to another row.
- **[Rules on the server](moves.md)**: the policy, durations, a locked past and your closures, checked again whatever the browser sent.
- **[Groups and resource timelines](groups.md)**: rows by project, assignee or room, collapsible, or one compact row per resource.
- **[Milestones, progress and dependencies](bars.md)**: diamonds, progress fills and finish-to-start arrows, dashed where a task starts too early.
- **[Filament actions on bars](actions.md)**: click to edit in a modal or slide-over, right-click for more, click an empty spot to create.
- **[Hour to year, zoom and Fit](filters.md)**: five scales, Ctrl/⌘ + wheel or a pinch, Fit for the whole picture, a date range.
- **[Search and filters](filters.md)**: select, multiple, Yes / No, toggle and date-range filters on Filament's own fields, kept in the URL.
- **[Keyboard and touch](moves.md)**: arrows move and resize a focused bar, announced to screen readers; a held bar drags on touch.
- **[Resource page, standalone page or widget](widget.md)**: `GanttResourcePage`, `GanttPage` and a read-only dashboard `GanttWidget`.
- **[No Gantt library, no build step](styling.md)**: one ES module on your panel's palette; dark mode, RTL, English, Romanian, Russian and German.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, your first chart on a resource page, a chart in any Livewire component |
| [Bars](bars.md) | Start and end attributes, whole days versus moments, label, colour, progress, milestones, the `Bar` shape |
| [Groups](groups.md) | Grouping by a column or a relation, fixed group lists, the resource-timeline recipe (rooms × bookings) |
| [Moving and resizing](moves.md) | What a drag does, snapping, every rule, `moveUsing()`, `afterMove()`, the `BarMoved` event |
| [Filters and the time window](filters.md) | Scales, the window and its bounds, the search box, select, multiple, Yes / No, toggle and date-range filters and where they are shown, refreshing and polling |
| [Actions](actions.md) | Click and right-click actions on bars, creating on an empty spot |
| [Widget and read-only mode](widget.md) | The dashboard widget, `readOnly()` |
| [Styling](styling.md) | The CSS variables, dark mode, RTL, translations |
| [Performance](performance.md) | What is drawn where, row windowing, eager loading, big windows |
| [Kanban + Gantt](kanban.md) | The same records on a board and on a timeline |
| [Upgrading](upgrading.md) | Version notes |

## At a glance

```bash
composer require packstub/filament-gantt
php artisan filament:assets
```

```php
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
            ->progress('progress')
            ->groupBy('project', label: 'project.name')
            ->barAction(EditAction::make()->schema(TaskResource::fields()));
    }
}
```

(`TaskResource::fields()` is your own helper returning the form components; `->schema(fn (Schema $schema) => TaskResource::form($schema))` reuses the resource's form.)

## Requirements

PHP 8.3+, Laravel 12 or 13, Filament 4.13+ or 5.

---

These pages are published at [packstub.dev/docs/filament-gantt](https://packstub.dev/docs/filament-gantt) from the package's `docs/` directory. Spotted a mistake? [Open a pull request](https://github.com/packstub/filament-gantt).
