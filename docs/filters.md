# Filters and the time window

## Scales

The toolbar switches between the zoom levels; `scale()` picks the one the chart opens with (default: the config's `default_scale`, week), `scales()` the ones offered. The user's choice is kept in the URL and in local storage.

| Scale | Pixels per day (config) | Axis |
| --- | --- | --- |
| `Scale::Hour` | 960 (40 an hour) | days over hours, weekends shaded, a line at now |
| `Scale::Day` | 48 | months over weekdays, weekends shaded |
| `Scale::Week` | 18 | months over weeks starting on `firstDayOfWeek()`, weekends shaded |
| `Scale::Month` | 5 | years over months |
| `Scale::Year` | 1.5 | quarters over months: a year or two in one screen, for roadmaps |

```php
use Packstub\Gantt\Enums\Scale;

->scales([Scale::Hour, Scale::Day, Scale::Week])   // default: day, week, month, year; hour first when the chart snaps to hours
->scale(Scale::Hour)
```

![The year scale: quarters over months, two years in one screen](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/year.png)

The hour level is for shifts, bookings and anything measured in hours: with `snapTo(Unit::Hour)` it is offered first. Only the hour columns in view are drawn, so a window of months stays light.

### Zoom and Fit

Between the levels the chart zooms continuously: **Ctrl/⌘ + mouse wheel** (a pinch on touch) stretches or squeezes the current level around the pointer, from half to twice its width, and hands over to the next level beyond that; a level picked in the toolbar is shown as designed again. The factor lives in the URL (`zoom=1.5`). **Fit** shows everything the filters leave in one screen: it loads the span of those bars (capped like any window by `max_window_days`) and draws it at the pixels per day that fill the chart's width, with every label drawn beside a bar still inside it.

A line in the panel's primary colour marks today (now, at the hour level); the Today button scrolls to it, and loads a window around today when the current one does not hold it. `firstDayOfWeek(7)` starts the week columns on Sunday; the config's `weekend_days` (ISO weekdays, `[6, 7]` by default) decides the shading. An axis label that does not fit its cell (a month cut to one day at the window's edge) is left out rather than drawn over the next one.

## The window

The chart draws the days between two dates:

1. what the user picked in the toolbar's "From / Until" (also in the URL, so a link shows the same span);
2. otherwise `startDate()` and `endDate()` from the definition;
3. otherwise a padded span around the bars: `window_padding_days.before` (7) before the earliest start to `window_padding_days.after` (14) after the latest end. When that span is longer than `max_window_days` (years of shifts or bookings), the chart opens around today instead: from `before` days before today to `after` days after it.

A window is stretched to at least `min_window_days` (28) and cut to at most `max_window_days` (730); the server decides, whatever the browser asked for. A range the user typed that was cut says so in a notification, and an "Until" before the "From" is read the other way round. Only the bars overlapping the window are loaded (a milestone by its start), at most `max_bars` (2,000) of them: when the window holds more, the toolbar shows "2,000 of 5,312 bars" (narrow the dates or filter).

A window narrower than the chart (zoomed out to months, or on a wide screen) grows at its end to fill the chart's width, so the grid never stops a third of the way.

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
- `Filter::dateRange()`: two date inputs; by default `where(<name>, '>=', from)` and `where(<name>, '<', until + 1 day)`, either side optional: plain ranges that keep the column's index in use and include the whole last day of a datetime column too. A `query()` closure receives `['from' => ?string, 'until' => ?string]`.

### Searchable selects

A select with many options (users, rooms, customers) can be searched. With `preload()` (the default) the options still come with the chart and the search runs on the server through `getSearchResultsUsing()`, or over the options' labels when there is none; with `preload(false)` nothing is listed until the user types. A searchable select accepts any value, so a value from a shared link that the list does not hold is looked up with `getOptionLabelUsing()` and applied; the query decides what it matches.

```php
Filter::select('assignee_id')->label('Assignee')->searchable()
    ->options(fn () => ['me' => 'Assigned to me'] + User::query()->orderBy('name')->limit(50)->pluck('name', 'id')->all())
    ->getSearchResultsUsing(fn (string $search) => User::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id')->all())
    ->getOptionLabelUsing(fn (string $value) => $value === 'me' ? 'Assigned to me' : User::find($value)?->name)
    ->query(fn (Builder $query, string $value) => $query->where('assignee_id', $value === 'me' ? auth()->id() : $value)),
```

`optionsLimit()` caps a list or a search (default 50). The select is Filament's own (the component behind the `Select` field), so it looks and behaves like one in a form; the search is a renderless call, nothing on the page re-renders.

Filters live in the URL too (`?f.assignee_id=3&f.starts_on.from=2026-10-01`).

The toolbar is built from Filament's own Blade components (tabs, button, input wrapper, Filament's date picker and select), so it follows the panel's theme, but it is not a Filament form: the chart is drawn by the browser and a filter change is a renderless call, so nothing on the page re-renders. A form-based filter (`Filter::form()`) is on the roadmap; until then, put a real Filament form in the page's header actions and dispatch `packstub-gantt-refresh` from it.

## Refreshing

The chart reloads its bars, keeping the window and the filters:

- after any bar or create action run from the chart;
- on the `packstub-gantt-refresh` event (`$this->dispatch('packstub-gantt-refresh')` from any Livewire component);
- on the Livewire events you list: `->refreshOn(['task-updated', 'import-finished'])`;
- every so often with `->poll('30s')` (or the config's `polling_interval`), skipping a beat while a bar is being dragged, moved from the keyboard or saved, and while the tab is in the background.

A background refresh never interrupts a drag: if one is in progress, the answer is dropped and the next refresh brings the latest.
