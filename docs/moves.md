# Moving and resizing

## What happens on a drag

1. The bar follows the pointer, snapped to the unit (whole days by default), and a hint shows the dates it would get. Dragging the body keeps the duration; dragging an edge changes it.
2. On release the bar stays where it was dropped, marked as pending, and the browser asks the server (`ganttMove`, a renderless Livewire call: nothing is re-rendered).
3. The server loads the record through the chart's `query()`, snaps the dates again and checks the rules below.
4. Your move logic runs (by default: write the start and end attributes and save).
5. The server answers with the bar as it looks now; the bar flashes once. If anything refused the move, the bar slides back and the reason is shown as a notification.

Read-only charts (`->readOnly()`, the widget's default) show no handles, and the server refuses moves anyway.

## Snapping

```php
use Packstub\Gantt\Enums\Unit;

->snapTo(Unit::Day)    // default: whole days
->snapTo(Unit::Hour)   // datetime records
```

The browser snaps while dragging and the server snaps what it receives, so a crafted request cannot store a time the chart would not draw.

## Rules

Every rule takes a boolean or a closure that receives the record, and is checked on the server whatever the browser sent:

```php
->movable(fn (Task $task) => $task->status !== 'done')       // may the bar change its dates?
->resizable(fn (Task $task) => ! $task->is_fixed_length)     // may its edges move?
->minDuration(days: 1)                                        // default: one snap unit
->maxDuration(days: 30, hours: 0)
->lockedBefore(today())                                       // or fn () => now()->startOfMonth()
->canMove(function (Task $task, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $previousStart, CarbonImmutable $previousEnd) {
    return $start->isWeekend() ? 'Not on a weekend.' : true;
})
```

| Rule | Refuses |
| --- | --- |
| `readOnly()` | Everything. |
| `movable()` | A change of position (same duration), and any move of a milestone. |
| `resizable()` | A change of duration, from either edge. Milestones are never resizable. |
| `minDuration()` / `maxDuration()` | A resize that makes the bar shorter or longer than that. |
| `lockedBefore($moment)` | A start moved to before the moment, a start that was already before it (the past stays as it happened), or an end moved to before it. A bar that started in the past may still change its end. |
| `canMove()` | Whatever the closure says: `false` refuses with a generic message, a string refuses with that message. |

The dates a closure receives are the chart's half-open range: a whole-day record's end is the day after its last day ([Bars](bars.md#whole-days-and-moments)).

## Your own move logic

`moveUsing()` replaces the default save. Throw to refuse the move: the bar goes back and the exception's message is shown.

```php
->moveUsing(function (Task $task, CarbonImmutable $start, CarbonImmutable $end) {
    $task->update(['starts_on' => $start, 'ends_on' => $end->subDay()]);
    $task->children()->each(fn (Task $child) => $child->shiftBy($start->diffInDays($task->starts_on)));
})
```

Throw `Packstub\Gantt\Exceptions\MoveRejected` to refuse with a message you want shown as-is; any other exception's message is shown too, or "The change did not go through." when it has none.

## After a move

`afterMove()` runs after a saved move, with the same arguments as `moveUsing()`:

```php
->afterMove(fn (Task $task, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $previousStart) =>
    activity()->performedOn($task)->log("Rescheduled from {$previousStart->toDateString()} to {$start->toDateString()}"))
```

The chart also dispatches a Laravel event:

```php
use Packstub\Gantt\Events\BarMoved;

Event::listen(function (BarMoved $event) {
    // $event->record, $event->previousStart, $event->previousEnd, $event->start, $event->end
    // $event->isResize(), $event->gantt (the chart's key())
});
```

On the page itself, override `ganttMoved(string $id, array $bar)` to react in the component. In the browser, a `gantt-bar-moved` event bubbles up from the chart with `{ id, bar }`.

## Refreshing from outside

Dispatch `packstub-gantt-refresh` (from Livewire, `$this->dispatch('packstub-gantt-refresh')`, or from JavaScript on `window`) and the chart reloads its bars with the current window and filters. Actions run from the chart do it for you; `refreshOn(['task-updated'])` does it for your own events ([Filters and the time window](filters.md#refreshing)).
