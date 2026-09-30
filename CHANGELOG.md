# Changelog

All notable changes to `packstub/filament-gantt` are documented in this file.

## 1.0.0 - Unreleased

First release: a Gantt chart and resource timeline for Filament 4 and 5.

- `Gantt` definition: `query()`, `start()`/`end()` (date or datetime casts, plain strings too), `label()`, `color()`, `progress()`, `milestone()`, `bar()` for the full shape, `groupBy()` by a column or a `BelongsTo` relation with `groups()` for a fixed, complete list (`Group::fromModels()`), `compact()` for the resource-timeline layout, `sortBy()`, `filters()`.
- Pages and widget: `GanttResourcePage` (access follows the resource policy), `GanttPage`, `GanttWidget` (read-only by default), and the `InteractsWithGantt` trait for any Livewire component.
- Axis: day, week and month scales with a two-row header, today line, weekend shading, `firstDayOfWeek()`, `startDate()`/`endDate()` or a padded window around the bars (clamped by `min_window_days`/`max_window_days`), a date-range picker and select/date-range filters; zoom, window and filters kept in the URL, folded groups in local storage.
- Moving and resizing: pointer-event drag to move (keeping the duration) or to resize either edge, snapped to `snapTo(Unit::Day|Unit::Hour)`; optimistic update, then a renderless `ganttMove`; the server re-checks `readOnly()`, `movable()`, `resizable()`, `minDuration()`, `maxDuration()`, `lockedBefore()` and `canMove()`, saves (or runs `moveUsing()`), fires `Packstub\Gantt\Events\BarMoved`, runs `afterMove()` and answers with the authoritative bar. A refused move snaps back with a notification.
- Actions: `barAction()` on click, `barActions()` in a right-click menu, `createAction()` on an empty spot with the start, end and group filled in; records resolved through the chart's query.
- Refresh: `refreshOn([...])` Livewire events, `poll()`, and the `packstub-gantt-refresh` event.
- Hand-written `gantt.js` (one pure `layout()` function, row windowing above 200 rows) and `gantt.css` on Filament's palette: dark mode, RTL through logical properties, no build step, no third-party Gantt library.
- Translations: en, ro, ru, de.
