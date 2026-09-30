# Filters and the time window

## Scales

The toolbar switches between three zoom levels; `scale()` picks the one the chart opens with (default: the config's `default_scale`, week). The user's choice is kept in the URL and in local storage.

| Scale | Pixels per day (config) | Axis |
| --- | --- | --- |
| `Scale::Day` | 48 | months over weekdays, weekends shaded |
| `Scale::Week` | 18 | months over weeks starting on `firstDayOfWeek()`, weekends shaded |
| `Scale::Month` | 5 | years over months |

A red line marks today. `firstDayOfWeek(7)` starts the week columns on Sunday; the config's `weekend_days` (ISO weekdays, `[6, 7]` by default) decides the shading.

## The window

The chart draws the days between two dates:

1. what the user picked in the toolbar's "From / Until" (also in the URL, so a link shows the same span);
2. otherwise `startDate()` and `endDate()` from the definition;
3. otherwise a padded span around the bars: `window_padding_days.before` (7) before the earliest start to `window_padding_days.after` (14) after the latest end.

A window is stretched to at least `min_window_days` (28) and cut to at most `max_window_days` (730); the server decides, whatever the browser asked for. Only the bars overlapping the window are loaded (a milestone by its start), so a long history stays cheap.

```php
->startDate(now()->startOfQuarter())
->endDate(now()->endOfQuarter())
```

## Filters

A `Filter` is a small control in the toolbar, applied on the server. Values it did not offer are ignored, so the browser cannot filter on anything you did not allow.

```php
use Packstub\Gantt\Filter;

->filters([
    Filter::select('assignee_id')->label('Assignee')->options(fn () => User::pluck('name', 'id')->all()),
    Filter::dateRange('starts_on'),
    Filter::select('state')->options(['late' => 'Late', 'open' => 'Open'])
        ->query(fn (Builder $query, string $value) => match ($value) {
            'late' => $query->where('ends_on', '<', today())->where('progress', '<', 100),
            'open' => $query->where('progress', '<', 100),
        }),
])
```

- `Filter::select()` (or `Filter::make()`): by default `where(<name>, <value>)`.
- `Filter::dateRange()`: two date inputs; by default `whereDate(<name>, '>=', from)` and `whereDate(<name>, '<=', until)`, either side optional. A `query()` closure receives `['from' => ?string, 'until' => ?string]`.

Filters live in the URL too (`?f.assignee_id=3&f.starts_on.from=2026-10-01`).

The toolbar's controls are native `<select>` and `<input type="date">` elements styled like Filament's inputs, not Filament form components: the chart is drawn by the browser and a filter change is a renderless call, so nothing on the page re-renders. A form-based filter (`Filter::form()`) is on the roadmap; until then, put a real Filament form in the page's header actions and dispatch `packstub-gantt-refresh` from it.

## Refreshing

The chart reloads its bars, keeping the window and the filters:

- after any bar or create action run from the chart;
- on the `packstub-gantt-refresh` event (`$this->dispatch('packstub-gantt-refresh')` from any Livewire component);
- on the Livewire events you list: `->refreshOn(['task-updated', 'import-finished'])`;
- every so often with `->poll('30s')` (or the config's `polling_interval`), skipping a beat while a bar is being dragged or saved and while the tab is in the background.

A background refresh never interrupts a drag: if one is in progress, the answer is dropped and the next refresh brings the latest.
