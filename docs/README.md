# Filament Gantt

![Filament Gantt](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/banner.jpg)

A Gantt chart and resource timeline for Filament v4 (4.13+) and v5. Any Eloquent query with a start and an end becomes draggable bars on an hour, day, week, month or year axis, grouped by whatever you like; the browser owns the chart, so a drag lands at once, and the server checks every rule again in the background. Free and open source (MIT). The sibling of [Filament Kanban](https://packstub.dev/docs/filament-kanban).

- Repository: [github.com/packstub/filament-gantt](https://github.com/packstub/filament-gantt)
- Packagist: [packstub/filament-gantt](https://packagist.org/packages/packstub/filament-gantt)
- Support: [GitHub issues](https://github.com/packstub/filament-gantt/issues)

## Features

- **[Drag to move, resize or reassign](moves.md)**: bars move at once, by an edge or to another row.
- **[Rules on the server](moves.md)**: policies, durations and a locked past, always checked.
- **[Groups and resource timelines](groups.md)**: rows by project, assignee, room or machine.
- **[Milestones, progress and dependencies](bars.md)**: diamonds, fills and finish-to-start arrows.
- **[Filament actions on bars](actions.md)**: click to edit, right-click for more, click to create.
- **[Hour to year, zoom and Fit](filters.md)**: five scales, wheel or pinch zoom, the whole plan in one screen.
- **[Search and filters](filters.md)**: Filament's filter fields, kept in the URL.
- **[Keyboard and touch](moves.md)**: move and resize with the arrows, drag on touch.
- **[Resource page, standalone page or widget](widget.md)**: wherever your panel needs a timeline.
- **[No Gantt library, no build step](styling.md)**: dark mode, RTL and four languages.

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
