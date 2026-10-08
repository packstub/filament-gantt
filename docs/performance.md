# Performance

## What is drawn where

The page renders the chart once as JSON: the window, the groups and the bars overlapping it. From then on the browser owns the chart. A drag moves a bar with no round trip; the server is asked afterwards with a renderless Livewire call that answers with one bar, never with HTML. Switching the scale, folding a group or scrolling touches the server not at all; changing the window or a filter loads the bars of the new window only.

When something else on the page re-renders the component (an action's modal closing, a header action, `$refresh`), the chart element is `wire:ignore` and that render carries no bars at all: the component remembers that the chart's state has been rendered (`$ganttRendered`, set by the render that included the chart), so the bars are neither queried nor serialised again, and the browser reloads them renderless when an action asks it to (`packstub-gantt-refresh`). A chart that appears only on a later render (behind a tab, say) gets its bars on that render; should a chart ever start without a window, it asks the server for its state itself. A later render leaves out the filters' options as well (often queries): a chart new on such a render asks for them along with its state.

All the maths lives in one pure function, `layout(bars, window, scale)` in `gantt.js`: bars in, pixel columns and rows out.

## Rows

Above 200 rows the chart windows them: only the rows in view (plus a margin) are in the DOM, the rest is empty space with the right height. Scrolling swaps rows in and out. A thousand bars stay smooth; the JSON for them is a few hundred kilobytes. Columns get the same treatment above 400 (hours over weeks): only those in view, and a screen either side, are drawn.

## Queries

- One query for the bars of the window (plus a count when it holds more than `max_bars`), one aggregate for the default window (min start, max end, read from a subquery of the two columns, so a `withCount()` or `addSelect()` in your query is fine on MySQL and PostgreSQL), one for a listed `groups()` closure. Filters are extra `where`s on the bars query.
- Eager-load what `label()`, `color()` and `bar()` touch: `->query(fn () => Task::query()->with(['project', 'assignee']))`. A `groupBy()` relation is eager-loaded for you when its label reads it.
- Each bar asks each bar action whether the user may run it on that record (Filament's visibility and authorization, a policy check per action and bar): keep `hidden()` and `visible()` closures to attribute checks.
- Index the start and end columns: the window filter is a plain range on each, `start < (last day + 1)` and `end >= first day` (never `DATE(column)`), so a B-tree index on either column is used. A `Filter::dateRange()` narrows its column the same way.
- Rules (`movable()`, `resizable()`, `authorizeMoves()`) are evaluated per bar while reading, so keep them to attribute checks; put queries in `canMove()`, which runs once per move. `readOnly()` is evaluated once per request.

## Windows

The default window is padded around the bars; when that is longer than `max_window_days` (730), the chart opens around today instead, and the date-range picker moves it. At most `max_bars` (2,000) bars are loaded per window. Set `startDate()`/`endDate()` to open on the span that matters (this quarter, the next six weeks).

## Polling

`poll()` reloads the window's bars every so often. It waits while a bar is being dragged, moved from the keyboard or saved and while the tab is hidden, and a stale answer is dropped, so it never fights the user.
