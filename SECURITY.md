# Security policy

Filament Gantt lets panel users move, resize and reassign records by dragging bars. The browser owns the chart and the server re-checks every move, so the plugin's job is to make sure a drag never writes what the user may not change, never reaches a record outside the chart's query, and never renders what a record holds as markup or CSS. Please report anything in that area privately so a fix can ship before details become public.

## Supported versions

Security fixes are released for the latest minor version of each supported release line.

| Version | Supported |
| ------- | --------- |
| 1.x     | Yes       |

## Reporting a vulnerability

Email **security@packstub.dev**. Do not open a public GitHub issue, discussion, or pull request for a security problem.

Include what you can: the package version (`composer show packstub/filament-gantt`), the Filament version, whether the chart is a `GanttResourcePage`, a `GanttPage`, a `GanttWidget` or the trait on your own component, the database driver, and a minimal reproduction.

What to expect: acknowledgment within 48 hours from a human, updates while we validate and fix, and coordinated disclosure with credit in the release notes unless you prefer otherwise.

## Scope

Highest priority:

- **Authorization of a move**: moving, resizing or regrouping a bar the user may not update (the resource's `update` policy on a `GanttResourcePage`, the model's policy or `authorizeMoves()` elsewhere), a bar the definition locks (`movable()`, `resizable()`, `lockedBefore()`, durations), or into a row that does not exist or accepts no bars.
- **Record reach**: any way for a browser to act on a record outside the chart's query (another tenant's or team's record) through `ganttMove`, a bar action or click-to-create.
- **Rendering**: bar labels, group labels, colours or URLs taken from records that reach the page as HTML, CSS (`style`) or a `javascript:` link.

Also in scope: the pages, the widget, the trait, the renderless Livewire methods, the Alpine component and the published views and config.

Out of scope: vulnerabilities in Laravel, Filament or Livewire themselves (report upstream; we still ship a mitigation when the exposure is specific to this package), issues that need an already-compromised admin account or server, and missing hardening in your own application.

## Hardening guidance for integrators

- **Moves follow a policy.** On a `GanttResourcePage` a move needs the resource's `canEdit($record)`. Elsewhere the chart uses the model's policy `update` when one exists; without a policy anyone who can open the page may move every bar. Set `authorizeMoves()` when that is not what you want.
- **Bar actions are yours to authorize outside a resource.** On a `GanttPage`, a widget or your own component, Filament's `EditAction`/`DeleteAction` in `barActions()` run for anyone who can open the page unless you add `->authorize('update', ...)`/`->authorize('delete', ...)` (see the moves docs).
- **Query is the boundary.** Every id the browser sends is resolved through the chart's `query()`; scope it to the tenant, the team or the user. A resource page's `getEloquentQuery()` already is.
- **Colours and URLs from records are validated** (`Bar::color()`, `Group::color()`, `Bar::url()` accept only colour shapes and safe schemes); labels are text, escaped, never rendered as HTML.
