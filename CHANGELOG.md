# Changelog

All notable changes to `packstub/filament-gantt` are documented in this file.

## Unreleased

Run `php artisan filament:assets` after updating.

- Split tasks: `lineBy('parent_id')` puts several records on one line, joined where the work stops and resumes
- Dragging a line's first piece moves the whole line, any other piece moves alone, and a drag to another group carries the line (`movePiecesAlone()` to turn the first off)
- Creating on a line's row adds a piece to that line; `preventLineOverlap()` keeps the pieces of a line apart
- Rows that hold several bars: `rowBy('machine')` gives machines, people or rooms a row of their own under each group, `rows()` lists them in order, empty ones included
- A bar dragged onto another row (or carried with ↑/↓) moves there; a click on a row creates in it; `preventRowOverlap()` keeps a row's bars apart
- Parent tasks as rows: `rowBy('parent')` on a relation to the same model makes every task without a parent a titled row and its children the bars on it
- Bars without a row share an Unassigned row at the end of their group; `unassignedRow('No machine')` names it and keeps it there to drop on

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
