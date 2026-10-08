# Filament Gantt

![Filament Gantt](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/banner.jpg)

A Gantt chart and resource timeline for Filament v4 (4.13+) and v5. Any Eloquent query with a start and an end becomes draggable bars on an hour, day, week or month axis, grouped by whatever you like; the browser owns the chart, so a drag lands at once, and the server checks every rule again in the background. Free and open source (MIT). The sibling of [Filament Kanban](https://packstub.dev/docs/filament-kanban).

- Repository: [github.com/packstub/filament-gantt](https://github.com/packstub/filament-gantt)
- Packagist: [packstub/filament-gantt](https://packagist.org/packages/packstub/filament-gantt)
- Support: [GitHub issues](https://github.com/packstub/filament-gantt/issues)

## What you get

| Feature | What it means for you |
| --- | --- |
| **Drag to move, drag an edge to resize** | The bar moves at once, snapped to the day or the hour, and the server is asked afterwards. Refused? It slides back with the reason. The chart scrolls when a bar nears its edge; on touch, hold a bar to pick it up. |
| **Keyboard too** | Arrow keys move a focused bar, Shift or Alt with an arrow resizes it, announced to screen readers: dragging is never the only way. |
| **Rules on the server** | Who may move (the model policy, the resource's `canEdit()`), `movable()`, `resizable()`, min and max durations, a locked past and your own `canMove()` closure, all re-checked in `Gantt::move()`. |
| **Groups and swimlanes** | Rows by project, assignee, room or machine, collapsible with a count; or the compact resource-timeline layout where a room's bookings share one row. |
| **Filament actions** | Click a bar to edit it in a modal, right-click for more, click an empty spot to create a record on that date. Each bar offers only the actions the user may run on its record. |
| **Four scales** | Hour, day, week and month, a two-row axis, a today line, weekend shading, a date-range picker and filters, all kept in the URL. |
| **Page, resource page or widget** | `GanttPage`, `GanttResourcePage` (follows the resource policy) and a read-only `GanttWidget` for the dashboard. |
| **Light** | No Gantt library, no build step: one hand-written script and a stylesheet on your panel's palette. Dark mode, RTL, four languages. |

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, your first chart on a resource page, a chart in any Livewire component |
| [Bars](bars.md) | Start and end attributes, whole days versus moments, label, colour, progress, milestones, the `Bar` shape |
| [Groups](groups.md) | Grouping by a column or a relation, fixed group lists, the resource-timeline recipe (rooms × bookings) |
| [Moving and resizing](moves.md) | What a drag does, snapping, every rule, `moveUsing()`, `afterMove()`, the `BarMoved` event |
| [Filters and the time window](filters.md) | Scales, the window and its bounds, select and date-range filters, refreshing and polling |
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
