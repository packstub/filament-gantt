# Bars

A bar is a record with a start and an end. The definition names the attributes and how the bar looks; the browser draws it.

```php
->start('starts_on')->end('ends_on')   // defaults: starts_at, ends_at
->label('title')                        // an attribute (an enum reads as its label), or fn (Task $task): string
->color('priority')                     // an attribute (a colour name, or an enum implementing HasColor), or a closure
->progress('progress')                  // 0–100, an attribute or a closure
```

Colours are names (`gray`, `sky`, `amber`, `emerald`, `rose`… or your panel's `primary`, `success`, `warning`, `danger`, `info`, or a colour you registered on the panel, `brand`), a Filament palette such as `Color::Sky` (its 500 shade is drawn), an enum implementing `HasColor`, or a CSS colour: `#0ea5e9`, `rgb(…)`, `hsl(…)`, `oklch(…)` and the other colour functions with plain values, `var(--my-colour)`. Anything else is dropped and the bar uses the panel's primary colour: the value ends up in the bar's style, so a colour taken from user data (a project's colour field) cannot bring declarations of its own (`red; position: fixed…`), on the server or in the browser. Without one, the bars use the panel's primary colour.

## Whole days and moments

The chart works in half-open ranges `[start, end)`:

| Cast | A record from the 5th to the 7th | Drawn as |
| --- | --- | --- |
| `date` (whole days) | `starts_on = 2026-10-05`, `ends_on = 2026-10-07` | three days, the 7th included |
| `datetime` (moments) | `starts_at = … 09:00`, `ends_at = … 12:30` | from 09:00 to 12:30 |

Whatever the browser does, the record is written back in the same convention: a whole-day record resized to end on the 9th gets `ends_on = 2026-10-09`. `->dateOnly()` (or `->dateOnly(false)`) overrides the detection for plain string columns. The tooltip follows the record too: whole days read as dates, moments as date and time.

Dates are handled as wall-clock times, never converted between zones, so a daylight-saving change never lengthens or shortens a bar ([Time zones](moves.md#time-zones)).

## Milestones

A record with a null end is a milestone: a diamond at its start, movable but not resizable. A datetime record whose end equals its start is one too. To decide yourself, give `milestone()` an attribute or a closure:

```php
->milestone('is_milestone')
->milestone(fn (Task $task) => $task->type === TaskType::Milestone)
```

Whole-day records with `starts_on == ends_on` are one-day bars, not milestones, unless `milestone()` says otherwise. A record `milestone()` flags keeps whatever end it has: when the diamond is dragged, its end moves by the same number of days.

## The Bar shape

For anything beyond the three shortcuts, shape the bar in one closure. What it sets wins over `label()`, `color()` and `progress()`:

```php
use Packstub\Gantt\Bar;

->bar(fn (Task $task) => Bar::make()
    ->label($task->title)
    ->color($task->is_late ? 'danger' : $task->priority)
    ->progress($task->progress)
    ->tooltip($task->assignee?->name)
    ->url(TaskResource::getUrl('edit', ['record' => $task]))
    ->actions($task->is_locked ? ['view'] : ['view', 'edit', 'delete']))
```

| Method | What it does |
| --- | --- |
| `label()` | The text inside the bar, or just after it when it does not fit (beside a milestone). |
| `color()` | A colour name, a Filament palette, an enum implementing `HasColor`, or a CSS colour (see above). |
| `progress()` | 0–100, a fill inside the bar; shown in the tooltip. |
| `tooltip()` | An extra line under the dates in the tooltip. |
| `url()` | Where a click goes when there is no [click action](actions.md); Ctrl/⌘-click always opens it in a new tab. Only `http(s)`, `mailto`, `tel` and relative URLs are kept: a `javascript:` or `data:` value from a user-editable column is dropped, never followed. |
| `actions()` | The chart's bar actions this bar offers, by name (default: all); the server refuses the others for this bar too. Within these, a bar offers only the actions the user may run on its record ([Actions](actions.md#which-actions-a-bar-offers)). |

Bars are plain data drawn in the browser, so a chart of a few hundred bars is a few kilobytes of JSON rather than a few hundred Blade components. Eager-load what the closures touch (`->query(fn () => Task::query()->with('assignee'))`).

## Dependencies

![Finish-to-start arrows between tasks, dashed where a task starts before its predecessor ends](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/gantt.png)

Tell the chart which bars a bar waits for and it draws an arrow from each predecessor's end to the bar's start (finish-to-start):

```php
->dependencies('depends_on')     // a JSON or array column holding the predecessors' keys
->dependencies('predecessors')   // a relation to them (BelongsToMany, HasMany), eager-loaded with the bars
->dependencies(fn (Task $task) => $task->blockers->pluck('id'))   // or a closure returning keys or models
```

The arrows are drawn, not enforced: a successor that starts before its predecessor ends gets a dashed arrow in the danger colour, and nothing is rescheduled or refused. To refuse such a move, say so in `canMove()`. An arrow needs both bars on the chart: a predecessor outside the window, filtered out or in a folded group draws none. The arrows follow a bar while it is dragged. Their colours are `--ps-gantt-arrow` and `--ps-gantt-arrow-late` ([Styling](styling.md)).

## Order

Bars inside a group are ordered by their start; `sortBy('priority', 'desc')` orders them by another attribute. Records without a start are left out.
