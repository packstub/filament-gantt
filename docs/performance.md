# Performance

## What is drawn where

The page renders the chart once as JSON: the window, the groups and the bars overlapping it. From then on the browser owns the chart. A drag moves a bar with no round trip; the server is asked afterwards with a renderless Livewire call that answers with one bar, never with HTML. Switching the scale, folding a group or scrolling touches the server not at all; changing the window or a filter loads the bars of the new window only.

When something else on the page re-renders the component (an action's modal closing, a header action, `$refresh`), the chart element is `wire:ignore` and that render carries no bars at all: the component remembers it has rendered the chart once (`$ganttRendered`), so the bars are neither queried nor serialised again, and the browser reloads them renderless when an action asks it to (`packstub-gantt-refresh`).

All the maths lives in one pure function, `layout(bars, window, scale)` in `gantt.js`: bars in, pixel columns and rows out.

## Rows

Above 200 rows the chart windows them: only the rows in view (plus a margin) are in the DOM, the rest is empty space with the right height. Scrolling swaps rows in and out. A thousand bars stay smooth; the JSON for them is a few hundred kilobytes.

## Queries

- One query for the bars of the window, one aggregate for the default window (min start, max end), one for a listed `groups()` closure. Filters are extra `where`s on the bars query.
- Eager-load what `label()`, `color()`, `bar()` and the group label touch: `->query(fn () => Task::query()->with(['project', 'assignee']))`.
- Index the start and end columns: the window filter is a plain range on each, `start < (last day + 1)` and `end >= first day` (never `DATE(column)`), so a B-tree index on either column is used. A `Filter::dateRange()` narrows its column the same way.
- Rules (`movable()`, `resizable()`) are evaluated per bar while reading, so keep them to attribute checks; put queries in `canMove()`, which runs once per move.

## Windows

The default window is padded around the bars and cut to `max_window_days` (730). A chart of a project spanning years opens on its first 730 days; the date-range picker moves the window. Set `startDate()`/`endDate()` to open on the span that matters (this quarter, the next six weeks).

## Polling

`poll()` reloads the window's bars every so often. It waits while a bar is being dragged or saved and while the tab is hidden, and a stale answer is dropped, so it never fights the user.
