# Widget and read-only mode

## The dashboard widget

`GanttWidget` puts a chart in a section on the dashboard (or on any page's `getWidgets()`), full width by default. It asks for the same `gantt()` method as a page:

```php
namespace App\Filament\Widgets;

use App\Models\Task;
use Packstub\Gantt\Enums\Scale;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Widgets\GanttWidget;

class UpcomingWork extends GanttWidget
{
    protected static ?string $heading = 'Upcoming work';

    protected string $height = '20rem';

    public function gantt(Gantt $gantt): Gantt
    {
        return $gantt
            ->query(fn () => Task::query()->whereBelongsTo(auth()->user(), 'assignee'))
            ->start('starts_on')->end('ends_on')
            ->label('title')
            ->scale(Scale::Week)
            ->startDate(today()->subWeek())->endDate(today()->addWeeks(5));
    }
}
```

The widget is **read-only by default**: no drag handles, no create click, and the server refuses moves. To make it live, say so: `->readOnly(false)` in `gantt()`. Bar actions work on the widget too (`barAction(EditAction::make()->schema([...]))`).

`$height` is the chart's height inside the section (a CSS length); `$columnSpan` is Filament's, `'full'` by default.

## Read-only charts

`->readOnly()` on any chart, or `->readOnly(fn () => ! auth()->user()->can('schedule'))`, shows the chart without handles or create clicks. It is a server rule like the others: `Gantt::move()` refuses, and the create action is hidden and refused.

Finer control is a rule away: `movable()` and `resizable()` per record ([Moving and resizing](moves.md#rules)).
