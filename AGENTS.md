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

- `src/Gantt.php` (definition + `getBars()`/`getState()` reads, `searchable()`/`applySearch()`, the server-side `move()`
  rules), `Bar.php`, `Group.php`, `Filter.php`, `Enums/{Scale,Unit,FiltersLayout,ParentSpan}.php`, `Events/BarMoved.php`, `Exceptions/MoveRejected.php`.
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
  No `x-for` inside an `<svg>` (its `<template>` is parsed as an SVG element, so the loop never runs): the arrows are
  two static `<path>` elements whose `d` joins every arrow of their kind.
- Who may move: `authorizeMoves()` (default: the model policy's `update` when it has one; `GanttResourcePage::makeGantt()`
  sets the resource's `canEdit()`), part of `isMovable()`/`isResizable()` and checked first in `move()`. Each bar's
  `actions` is the list the user may run on its record (`GanttActions::ganttActionsFor()` evaluates every action's
  visibility with `$ganttActionRecord` set), within `Bar::actions()`, which `offersAction()` enforces on mount.
- `move()` runs the save, `BarMoved`, `afterMove()` and the returned bar (read back through `baseQuery()`) in one
  transaction: the browser's rollback and the database agree. Date-only records snap by `Unit::Day` (`unitFor()`).
  Every rule lives in `planMove()` (checks, writes nothing) and the save in `applyMove()`; `moveLine()` plans every
  piece of a line before it applies any, so one refusal refuses the line. A new rule goes in `planMove()`.
- Lines (`lineBy()`, issue #38): a record's line is `parent ?? own key`, one level deep; `presentBar()` adds `line`,
  `getState()` adds `lines` (only lines with 2+ pieces, every piece through `baseQuery()`, in or out of the window,
  as a list, never a keyed object: numeric keys would make a JSON array). In the browser `layout()` buckets a group's
  bars by line (`lineLayout`, not in compact) and `pack(bucket, 0)` (touching pieces share a lane). The first piece
  (earliest, the line's own record first among equals) carries the line, any piece carried to another group carries
  it (`commitLine()` → `ganttMoveLine`), others move alone; `walls()` clamps with `preventLineOverlap`. The server
  checks overlap in `planMove()` (not along a line, not for a row change alone) and in the create/edit actions'
  `mutateDataUsing` (`ganttGuardOverlap()`: notification + `halt()`). `canCreateOnLine()` is the line's `canCreateIn()`.
- Parent rows (issue #41 step 2): `rowBy()` through a `BelongsTo` to the chart's own model (`hasParentRows()`) makes
  the parents the rows: `windowQuery()` and `spanOf()` keep only the records with a parent, `parentRows()` lists the
  records without one that `filteredQuery()` leaves (`listed: true`, each in its own group, which `getGroups()` adds)
  and names the parents met on a bar only through `baseQuery()` (never the bar's relation); no unassigned row
  (`knowsRowKey('')` is false), `descendsFrom()` refuses a loop in `planMove()`. The browser needs nothing for it.
- The parent's span (issue #41 step 3): `parentSpan()` (`Enums\ParentSpan`: Own / FromChildren / None). Own: a parent
  with dates is a bar like any other with `parent: true` and `row` = its own key (`presentBar()`); FromChildren:
  `childSpanBars()` appends one bar per parent from `childSpans()` (one grouped query over `baseQuery()`, filters
  aside; `computed: true`, never resizable, not in `total` or a group's count); None: only the rows. A parent is
  never regroupable (`isParentRecord()` in `isRegroupable()`), and `planMove()` refuses its own dates unless Own.
  `moveParent()` / `ganttMoveParent` is `moveLine()` for a parent and its children (`planMove(together: true)` skips
  both overlap checks, a child's refusal is `child_part`). In the browser the span is `row.bars[0]`, in no lane
  (`bar.lanes`), drawn by `barStyle()` over the row's height with `.ps-gantt-bar-span`; a whole drag or ←/→ shifts
  the children as `mates` (`p.family` / `k.family`) and ends in `commitFamily()`; `rowWalls()` / `rowTaken()` skip
  `bar.parent`; a click on the span is `createUnder()` when the chart creates; a row's `actions` (the parent's) are
  on its name (`rowRecord()`, `openRow()`, `rowMenu()`); after a child's move in `children` mode, `refresh(true)`.
- Parent rules (issue #41 step 4): `keepInsideParent()` (only with Own: `keepsInsideParent()`) is `leavesParent()` in
  `planMove()` (skipped for `together`) and `outsideParent()` in the actions' `ganttGuardOverlap()`; in the browser
  `wallsFor()` adds `parentWalls()` to a child's walls and `childHold()` (`p.hold` / `k.hold`) stops a parent's edge
  or lone move at its children. `writeSpanToParent()`: `applyMove()` calls `writeParentSpans(previousRow, newRow)`
  inside the move's transaction (not for `together`), the actions' `before`/`after` hooks (`ganttRefreshAfter()`)
  do the same around create, edit and delete; the browser reloads after a child's move (`refresh(true)`).
- Lines inside a row (issue #41 step 5): `rowBy()` + `lineBy()` on two columns (`hasRows()` throws for the same
  one). `layout()` still takes the `rowLayout` branch: `packRow()` gives a line's pieces one lane (a unit from first
  to last) unless they overlap each other, `gaps()` chains per line on a `'row'` row (`gap.lane`, `gap.line`). A
  piece carried to another row carries its line: `carried` also for `p.r !== p.r0`, `commitLine(…, row, rows0)` →
  `ganttMoveLine(id, start, group, row)` → `moveLine(…, $row)`; ↑/↓ use `regroupLine(key, group, id, row)` and
  `k.rows0`. `planMove()`'s flags: `together` (a line: skips the line-overlap check), `moving` (keys that move
  along: skipped by `overlapsRow()`), `withParent` (`moveParent()`: skips `leavesParent()` and the parent's span
  write). `barWalls()` merges the line's and the row's walls (none while the bar carries its line).
- Rows (`rowBy()`, issue #41 step 1): a column or a `BelongsTo`;
  `hasRows()` throws with `compact()`. `presentBar()` adds `row` ('' for none), `getState()` adds `rows`
  (`rows()` first with `listed: true`, then those met on bars with `listed: false`). In `layout()` a group holds its
  listed rows (`group` null: every group), then met ones, then the unassigned row (`row` '': the bars without a
  row; `unassignedRow()` names it and keeps it in every group, empty or not, which also makes '' a row
  `knowsRowKey()` accepts). Every row of rowBy() is type `'row'` with `row` and `color`, keyed `r:<len>:<group>:<row>`. A drag targets view rows (`targetRow`), not
  groups; `commit()` sends `ganttMove(id, s, e, group|null, row)`; ↑/↓ walk `rowTargets()`. A group change needs
  `movesBetweenGroups()` (`groupMoves` in the config); a row change `isRegroupable()` and `knowsRowKey()`;
  with a writable group, any change of group or row also needs `rowShowsIn()` (the row shows in the landing group:
  listed for it or for all, or a bar of that group is in it), and so does `canCreateInRow()`.
  `preventRowOverlap()` is per row key across groups (`overlapsRow()` in `planMove()` and `ganttGuardOverlap()`,
  `rowWalls()` in the browser, `rowTaken()` for a bar carried to another row: `pointer.blocked`, no request on release); the closures and `BarMoved` get the rows after the groups.
- Colours end up in a style attribute: `Bar::safeColor()` (PHP) and `tone()` (JS) keep colour shapes only, and the
  Blade binds `barStyle()`/`cell()` as objects, never concatenated strings.
- `init()` sets `this.fit` (the height fitter on window resize): a method of that name is overwritten after init, so
  the Fit button's method is `fitAll()`.
- Alpine hands back reactive proxies: after `this.pointer = p`, compare and mutate `this.pointer`, never `p` (the
  harness proxies `pointer`/`keyboard`/`menu` the same way, so such a bug fails `composer test:js`).
- Never `this.$root`/`$refs`/`$wire`/`$dispatch` in `gantt.js`: Alpine binds them to the element the expression ran
  on, and a bar's key or pointer handler keeps running after a rebuild destroyed that element (↑/↓ on a compact
  row), when they resolve to nothing. `init()` keeps the chart's own; use `this.ui('root' | 'refs' | 'wire' | 'dispatch')`.
- The chart is a table-style container (`.ps-gantt`, the shared shell of Kanban, Gantt and Spreadsheet: the first
  block of `gantt.css`, the `--ps-gantt-*` scheme): a two-group toolbar (`ps-gantt-toolbar-start`: scale tabs, Today,
  Fit, From / Until; `ps-gantt-toolbar-end`: the search box when `searchable()`, the filters where `filtersLayout()`
  puts them), the scrolling chart, the bar count in `ps-gantt-footer`. Every control is Filament's own component; the
  bar menu wears `fi-dropdown-panel` / `fi-dropdown-list-item`. The filters and the window's From / Until are Filament's own form components
  (`Select`, `Toggle`, `DatePicker`, a `Fieldset` for a date range), built by `Filter::toFormComponent()` and rendered
  through two schemas on the component (`ganttFiltersForm` → `$ganttFilters`, `ganttWindowForm` → `$ganttWindow`, filled on
  mount by `mountInteractsWithGantt()`; so the component needs `HasSchemas` / `InteractsWithSchemas`, the pages and widget
  have it). No copied Filament markup anywhere: a searchable select's search goes through Filament's own
  `callSchemaComponentMethod`. The state stays the browser's: each field dispatches its change with `afterStateUpdatedJs`
  (`ps-gantt-filter` `{name, value, edge?}`, `ps-gantt-range` `{edge, value}`) to `setFilter()` / `pickRange()`, and the
  browser writes its state back into the fields without a request (`syncFields()` / `syncWindow()`:
  `$wire.$set(path, value, false)`), so a restored URL, Reset and the window drawn show in the fields; the handlers ignore
  an echo equal to what they hold. The funnel dropdown is `:menu="false"` (a menu would take the keys typed in a search).
  `Filter` is the kit class shared with Kanban and Spreadsheet (select, multiple, searchable,
  boolean, toggle, dateRange; `state()`/`emptyState()` for the URL); the browser's `active[name]` holds '' / [] / false /
  `{from, until}` and `isActive()`, `toggleFilter()`, `resetFilters()`, `activeFilterCount()` have the same names in all three.
- Every change needs a test; `CHANGELOG.md` stays current. Strings in `resources/lang/{en,ro,ru,de}` (same keys in
  all four; plural forms as Laravel's locale rules, `bars` as Intl.PluralRules categories for the browser).
