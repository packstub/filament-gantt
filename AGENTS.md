# packstub/filament-gantt

Free (MIT) Filament v4/v5 plugin: a Gantt chart and resource timeline. The browser owns the chart (Alpine state drawn
from JSON, pointer-event drag, no Gantt library); Livewire only answers renderless calls (`ganttMove`, `ganttRefresh`,
and the action mounts). Sibling of `plugins/filament-kanban`: same scaffold, same actions pattern, same `Filter`.

## Commands

```bash
composer test               # Pest suite
composer test:filter <name>
composer test:js            # browser side: node --test tests/js (layout(), drag, menu; plain Node, no packages)
composer lint               # Pint
```

CI runs all three on Laravel 12/13 × Filament 4.*/5.* (floor `^4.13`), the JS suite on a pinned Node 22 (`actions/setup-node`),
and the Pest suite again on MySQL 8.4 and PostgreSQL 17 (the `databases` job): SQLite accepts SQL they refuse. Locally:
`DB_CONNECTION=pgsql DB_DATABASE=… DB_USERNAME=… vendor/bin/pest` (the TestCase drops all tables first on a real server).

## Layout

- `src/Gantt.php` (definition + `getBars()`/`getState()` reads + the server-side `move()` rules), `Bar.php`, `Group.php`,
  `Filter.php`, `Enums/{Scale,Unit}.php`, `Events/BarMoved.php`, `Exceptions/MoveRejected.php`.
- `src/Concerns/InteractsWithGantt.php` (the Livewire side), `src/Concerns/GanttActions.php` (bar and create actions wired
  into Filament's action system: record through the chart's query, hidden/mount/mutate/after chained onto the app's own),
  `src/Pages/Gantt{,Resource}Page.php`, `src/Widgets/GanttWidget.php` (read-only by default), `src/GanttPlugin.php`.
- `resources/views/gantt.blade.php`, `resources/dist/gantt.{js,css}` — hand-written, no build step. `layout(bars, window,
  scale)` in `gantt.js` is pure and exported; `tests/js/` (node:test, `harness.mjs` fakes the few globals the component
  touches) covers it and the component's drag, menu and URL handling: add a case there for any change. After editing the
  assets in a consuming app, run `php artisan filament:assets` there (assets are copied, not linked).
- `tests/Fixtures/TaskGantt.php` — a plain Livewire component (with Filament actions) holding a chart; `Task` has `date`
  casts, `Shift` has `datetime` casts; `AdminPanelProvider` + `TaskResource` for the resource page and widget tests.
- `docs/` — the packstub.dev pages (synced by `docs-sync.yml`); `docs/images/` is taken by the demo's screenshot script.

## Conventions

- Dates: the chart works in half-open ranges `[start, end)`. A date-only record ending on the 7th has a chart end of the
  8th 00:00 and is written back as the 7th. A milestone (null end, or a datetime end equal to its start) has start == end.
- Time zones: naive on purpose. `toCarbon()`/`parse()` return wall-clock moments tagged UTC (`shiftTimezone('UTC')`), so
  minute maths never crosses a DST change. The closures (`canMove`, `moveUsing`, `afterMove`) and `BarMoved` get
  `inAppTimezone()` copies (`shiftTimezone(app.timezone)`: same wall clock, Eloquent's tag) so `isPast()`/`now()` work;
  `store()` and the rules keep the UTC ones. Never `setTimezone()` in the chart's path. Snapping is relative
  (`Unit::snapFrom()`): the delta is rounded (half up, like `Math.round`), the time of day kept, like the browser.
- Rules live on the server: anything the browser checks (`movable`, `resizable`, snapping, min/max duration…)
  `Gantt::move()` checks again; a move is classified by its edges (both edges + new length needs movable AND resizable).
  The browser rounds `minMinutes`/`maxMinutes` to whole units (`limits`) so what it allows the server accepts.
  `canCreateIn()` validates the clicked group before the create action mounts, like Kanban's: only `groups()`, groups of
  bars in `baseQuery()` and ungrouped (plus `creatableIn()`), never "a related record that exists".
- Render once: `$ganttRendered` (`#[Locked]`, set by `getGanttConfig()` when it produced the state, so by the render that
  included the chart, not by a render that did not) makes `getGanttConfig()` skip `getState()` on every render after that;
  the chart element is `wire:ignore`, so a re-render (an action's modal, `$refresh`) never reaches the browser's chart.
  Such a render sends `filters: null` too (their options are often queries); the browser asks for them with
  `ganttRefresh(…, withFilters: true)`.
  A config without a window makes `init()` call `refresh()` (the browser fetches the state itself). Tests that want the
  bars call `getGanttConfig(withState: true)`.
- Errors: `ganttMove` answers with a `MoveRejected`'s message only; any other exception is `report()`ed and answered with
  the generic `failed` string (a `QueryException` carries the SQL and bindings). `Gantt::move()` lets it propagate.
- `parse()` (what the browser sends) is strict: `Y-m-d`, `Y-m-d H:i[:s]`, years 1000–9999, a real calendar date; the
  action arguments (`ganttRecord`, `ganttStart`, `ganttEnd`, `ganttGroup`) are read as strings or nothing.
- `safeUrl()` (PHP and JS, same rules): strip C0/space, refuse `^[\/\\]{2}` (protocol-relative hosts), allow-list schemes.
- In the Blade view, an element with `x-show` binds `:style` with the object syntax (a string `:style` overwrites the
  `display` that `x-show` sets). `:class` takes a string or one object, never an array holding an object (Alpine joins
  an array with spaces, so the object becomes `[object Object]`): `{ ['ps-gantt-row-' + row.type]: true, 'x': cond }`.
- Who may move: `authorizeMoves()` (default: the model policy's `update` when it has one; `GanttResourcePage::makeGantt()`
  sets the resource's `canEdit()`), part of `isMovable()`/`isResizable()` and checked first in `move()`. Each bar's
  `actions` is the list the user may run on its record (`GanttActions::ganttActionsFor()` evaluates every action's
  visibility with `$ganttActionRecord` set), within `Bar::actions()`, which `offersAction()` enforces on mount.
- `move()` runs the save, `BarMoved`, `afterMove()` and the returned bar (read back through `baseQuery()`) in one
  transaction: the browser's rollback and the database agree. Date-only records snap by `Unit::Day` (`unitFor()`).
- Colours end up in a style attribute: `Bar::safeColor()` (PHP) and `tone()` (JS) keep colour shapes only, and the
  Blade binds `barStyle()`/`cell()` as objects, never concatenated strings.
- Alpine hands back reactive proxies: after `this.pointer = p`, compare and mutate `this.pointer`, never `p` (the
  harness proxies `pointer`/`keyboard`/`menu` the same way, so such a bug fails `composer test:js`).
- Every change needs a test; `CHANGELOG.md` stays current. Strings in `resources/lang/{en,ro,ru,de}` (same keys in
  all four; plural forms as Laravel's locale rules, `bars` as Intl.PluralRules categories for the browser).
