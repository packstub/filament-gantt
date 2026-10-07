# Moving and resizing

## What happens on a drag

1. The bar follows the pointer, snapped to the unit (whole days by default), and a hint shows the dates it would get. Dragging the body keeps the duration; dragging an edge changes it, within `minDuration()` and `maxDuration()`. Near the chart's start or end edge the chart scrolls, faster the closer the pointer, and the bar keeps following. Escape puts the bar back and sends nothing. A bar too narrow for its two resize handles (a one-day bar at the month scale) has none: grabbed anywhere, it moves; resize it from the keyboard or zoom in.
2. On release the bar stays where it was dropped, marked as pending, and the browser asks the server (`ganttMove`, a renderless Livewire call: nothing is re-rendered).
3. The server loads the record through the chart's `query()`, snaps the dates again and checks the rules below.
4. Your move logic runs (by default: write the start and end attributes and save).
5. The server answers with the bar as it looks now; the bar flashes once. If anything refused the move, the bar slides back and the reason is shown as a notification.

Read-only charts (`->readOnly()`, the widget's default) show no handles, and the server refuses moves anyway.

### On touch

A swipe always scrolls the chart, wherever it starts, so scrolling a phone screen never reschedules anything. To move a bar, press and hold it until it lifts (the device vibrates where it can), then drag. Held and lifted without moving, the bar opens its menu; a tap runs the click action.

### From the keyboard

A bar is a focusable button. On a focused bar:

| Keys | Does |
| --- | --- |
| ← / → | Move the bar by one snap unit (mirrored right to left). |
| Shift + ← / → | Move its end (resize). |
| Alt + ← / → | Move its start (resize). |
| Enter, Space | The click action. |
| Escape | Before the move is sent: put the bar back. |

The bar moves at once; keys pressed in a row become one move, sent once they rest, through the same server checks as a drag. The new dates, or the reason a move was refused, are announced to screen readers, and the bar's accessible name ends with these keys. This is the keyboard and single-pointer alternative to dragging that WCAG 2.2 asks for (2.5.7 Dragging Movements).

## Snapping

```php
use Packstub\Gantt\Enums\Unit;

->snapTo(Unit::Day)    // default: whole days
->snapTo(Unit::Hour)   // datetime records
```

A whole-day record (a `date` cast) has no hours to move: it moves by whole days, whatever `snapTo()` says. Snapping is relative to where the bar was: the distance dragged is rounded to whole units and the time of day is kept, so a shift at 08:00 dragged by a day is still at 08:00 with the default unit, and a bar that started at 09:30 keeps its half hour when dragged by the hour. The browser snaps while dragging and the server snaps what it receives the same way, so a crafted request cannot store a move the chart would not draw.

## Time zones

The chart is naive: it works in wall-clock time and never converts. Every moment on the server side (a record's dates, what the browser sends, `lockedBefore()`, the window) is read as a wall-clock time and tagged UTC, whatever `app.timezone` is. A whole day is always 1440 minutes, so a three-day bar dragged across a daylight-saving change is still three days long, and a move is never mistaken for a resize because an hour went missing overnight.

The `CarbonImmutable` instances handed to `canMove()`, `moveUsing()`, `afterMove()` and the `BarMoved` event carry that same wall-clock time, tagged with the app's timezone (`app.timezone`), the way Eloquent tags a record's dates. So they read naturally: `$start->isPast()`, `$start < now()`, `$end->lte($task->deadline)`, and writing one back with `$task->update(['starts_on' => $start])` stores the wall clock unchanged. Only the chart's own maths (snapping, durations, the lock) stays on UTC-tagged copies. One edge case: a datetime record sitting exactly inside the hour a spring-forward change skips has no valid wall-clock time in the app's zone, so its copy lands an hour later; Eloquent saves that hour forward as well, whichever path writes it (the default save or your `moveUsing()`), since a `datetime` cast resolves the missing wall-clock time the same way.

## Who may move

`authorizeMoves()` says who may move and resize a record from the chart; `movable()` and `resizable()` below say what may change. By default a move needs the model policy's `update` ability, when the model has a policy with an `update` method. On a `GanttResourcePage` it needs the resource's `canEdit($record)`, which asks the same policy unless your resource says otherwise. A record the user may not update is drawn locked and its moves are refused on the server.

```php
->authorizeMoves(fn (Task $task) => auth()->user()->can('reschedule', $task))
->authorizeMoves(true)   // anyone who sees the chart
```

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
| `authorizeMoves()` | Any move or resize by a user it does not allow (default: the policy's `update`). |
| `movable()` | A change of position (same duration), any move of a milestone, and a change of both edges together with the duration (that is a move as well as a resize, so it needs both). |
| `resizable()` | A change of duration, from either edge. Milestones are never resizable. |
| `minDuration()` / `maxDuration()` | A resize that makes the bar shorter or longer than that. The browser knows the bounds too and stops the handle there. |
| `lockedBefore($moment)` | A start moved to before the moment, a start that was already before it (the past stays as it happened), or an end moved to before it. A bar that started in the past may still change its end. The message names the moment: a date for a midnight lock (`today()`), date and time otherwise (`now()`). |
| `canMove()` | Whatever the closure says: `false` refuses with a generic message, a string refuses with that message. |

The dates a closure receives are the chart's half-open range: a whole-day record's end is the day after its last day ([Bars](bars.md#whole-days-and-moments)), as wall-clock moments in the app's timezone ([Time zones](#time-zones)), so `$start->isPast()` means what it says.

## Your own move logic

`moveUsing()` replaces the default save. Throw `MoveRejected` to refuse the move: the bar goes back and the message is shown.

```php
->moveUsing(function (Task $task, CarbonImmutable $start, CarbonImmutable $end) {
    $task->update(['starts_on' => $start, 'ends_on' => $end->subDay()]);
    $task->children()->each(fn (Task $child) => $child->shiftBy($start->diffInDays($task->starts_on)));
})
```

Throw `Packstub\Gantt\Exceptions\MoveRejected` to refuse with a message you want shown as-is. Any other exception (the database's, a bug in the closure) is reported to your exception handler and the user sees "The change did not go through.": an exception's text, a query with its bindings say, belongs in the log, not in a notification. `Gantt::move()` itself lets such an exception propagate, so a call of your own sees it.

```php
use Packstub\Gantt\Exceptions\MoveRejected;

->moveUsing(function (Booking $booking, CarbonImmutable $start, CarbonImmutable $end) {
    throw_if($booking->isLocked(), new MoveRejected('This booking is locked.'));

    $booking->update(['check_in' => $start, 'check_out' => $end]);
})
```

## After a move

`afterMove()` runs after a saved move, with the same arguments as `moveUsing()`. The save (or your `moveUsing()`), the `BarMoved` event, `afterMove()` and the bar sent back run in one database transaction on the model's connection: when `afterMove()` or a listener throws, the save is undone, so the bar going back in the browser matches the database. Throw `MoveRejected` from `afterMove()` to refuse with a message. Work that must not be undone, or must not run for a move that is (mail, a call to another service), belongs in a queued listener.

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
