# packstub/filament-gantt

Free (MIT) Filament v4/v5 plugin: a Gantt chart and resource timeline. The browser owns the chart (Alpine state drawn
from JSON, pointer-event drag, no Gantt library); Livewire only answers renderless calls (`ganttMove`, `ganttRefresh`,
`ganttWindow`). Sibling of `plugins/filament-kanban`: same scaffold, same actions pattern, same `Filter`.

## Commands

```bash
composer test               # Pest suite
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Gantt.php` (definition + `getBars()`/`getState()` reads + the server-side `move()` rules), `Bar.php`, `Group.php`,
  `Filter.php`, `Enums/{Scale,Unit}.php`, `Events/BarMoved.php`, `Exceptions/MoveRejected.php`.
- `src/Concerns/InteractsWithGantt.php` (the Livewire side), `src/Concerns/GanttActions.php` (bar and create actions wired
  into Filament's action system: record through the chart's query, hidden/mount/mutate/after chained onto the app's own),
  `src/Pages/Gantt{,Resource}Page.php`, `src/Widgets/GanttWidget.php` (read-only by default), `src/GanttPlugin.php`.
- `resources/views/gantt.blade.php`, `resources/dist/gantt.{js,css}` — hand-written, no build step. `layout(bars, window,
  scale)` in `gantt.js` is pure and exported: exercise it with a Node script when it changes. After editing the assets in a
  consuming app, run `php artisan filament:assets` there (assets are copied, not linked).
- `tests/Fixtures/TaskGantt.php` — a plain Livewire component (with Filament actions) holding a chart; `Task` has `date`
  casts, `Shift` has `datetime` casts; `AdminPanelProvider` + `TaskResource` for the resource page and widget tests.
- `docs/` — the packstub.dev pages (synced by `docs-sync.yml`); `docs/images/` is taken by the demo's screenshot script.

## Conventions

- Dates: the chart works in half-open ranges `[start, end)`. A date-only record ending on the 7th has a chart end of the
  8th 00:00 and is written back as the 7th. A milestone (null end, or a datetime end equal to its start) has start == end.
- Rules live on the server: anything the browser checks (`movable`, `resizable`, snapping…) `Gantt::move()` checks again.
- In the Blade view, an element with `x-show` binds `:style` with the object syntax (a string `:style` overwrites the
  `display` that `x-show` sets).
- Every change needs a test; `CHANGELOG.md` stays current. Strings in `resources/lang/{en,ro,ru,de}`.
