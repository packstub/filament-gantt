# Changelog

All notable changes to `packstub/filament-gantt` are documented in this file.

## Unreleased

## 1.0.0-beta.3 - 2026-11-13

Run `php artisan filament:assets` after updating. Rows that hold several bars, parent tasks and split tasks: the chart now draws schedules as well as task lists.

- Rows: `rowBy('machine')` gives machines, people or rooms a row of their own, empty ones included; drag, ↑/↓ or click to plan in them, `preventRowOverlap()` keeps their bars apart, bars without a row share an Unassigned row
- Parent tasks: `rowBy('parent')` makes each parent a row with its children on it; its span moves them all together, `parentSpan()` picks its dates (own, from its children or none)
- `keepInsideParent()` keeps children within their parent's dates; `writeSpanToParent()` writes the parent's dates from its children
- Split tasks: `lineBy('parent_id')` puts several records on one line, also inside a row; the first piece carries the line, `preventLineOverlap()` keeps the pieces apart
- The chart's script and stylesheet ship minified: about 21 KB gzip instead of 40 KB
- Fixed: a drag on a chart of over 200 rows no longer sticks when a scroll takes the bar's row out of view

## 1.0.0-beta.2 - 2026-11-09

Run `php artisan filament:assets` after updating.

- Press and drag across an empty stretch of a row to create a record for that period, in that row's group
- A folded group draws a line from its first bar to its last, so the row still shows where the group sits; hover it for the dates

## 1.0.0-beta.1 - 2026-10-28

First beta of Filament Gantt: a Gantt chart and resource timeline over any Eloquent query with a start and an end. Works with Filament 4.13+ and 5.

- Hour, day, week, month and year scales, with zoom, Fit and Today
- Drag to move or resize bars, or carry them to another row; every rule is checked on the server
- Dependency arrows, groups, milestones, progress and a compact timeline layout
- Click and right-click actions on bars, and create a record by clicking an empty spot
- Search and the same filters and look as Kanban and Spreadsheet, kept in the URL
- Keyboard and touch support, dark mode, RTL; tested on SQLite, MySQL 8.4 and PostgreSQL 17

Docs: https://packstub.dev/docs/filament-gantt
