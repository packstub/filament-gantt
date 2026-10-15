<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Axis
    |--------------------------------------------------------------------------
    |
    | The zoom level a chart opens with (hour, day, week or month; a
    | definition's scale() wins), the ISO weekday the week columns start on
    | (1 = Monday … 7 = Sunday) and the weekdays shaded as weekend.
    |
    */

    'default_scale' => 'week',

    'first_day_of_week' => 1,

    'weekend_days' => [6, 7],

    /*
    |--------------------------------------------------------------------------
    | Sizes
    |--------------------------------------------------------------------------
    |
    | The height of a row in pixels, and how many pixels a day takes at each
    | zoom level (960 at the hour level: 40 pixels an hour).
    |
    */

    'row_height' => 36,

    'px_per_day' => [
        'hour' => 960,
        'day' => 48,
        'week' => 18,
        'month' => 5,
        'year' => 1.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Time window
    |--------------------------------------------------------------------------
    |
    | Without startDate()/endDate() a chart shows from `before` days ahead of
    | its earliest bar to `after` days past its latest one; when that is
    | longer than `max_window_days` (years of history), from `before` days
    | ahead of today to `after` days past it. A window the chart derives is
    | at least `min_window_days` long (a range typed in full, or the span Fit
    | loads, is shown as asked); every window is at most `max_window_days`
    | long and loads at most `max_bars` bars (the toolbar says when there are
    | more).
    |
    */

    'window_padding_days' => [
        'before' => 7,
        'after' => 14,
    ],

    'min_window_days' => 28,

    'max_window_days' => 730,

    'max_bars' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Refresh
    |--------------------------------------------------------------------------
    |
    | Milliseconds between two refreshes from the server, for every chart
    | (null: none; a definition's poll() wins).
    |
    */

    'polling_interval' => null,

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    |
    | Where a chart shows its filters: 'dropdown' (behind a funnel button in
    | the toolbar, with the count of active ones, as a table's), 'toolbar'
    | (inline, after the search box) or 'above' (a row of their own between
    | the toolbar and the chart). A definition's filtersLayout() wins.
    |
    */

    'filters_layout' => 'dropdown',

    /*
    |--------------------------------------------------------------------------
    | Formats
    |--------------------------------------------------------------------------
    |
    | PHP date formats for the dates in tooltips and messages: whole days, and
    | records with a time. Null: the locale's (`M j, Y` in English, `j. M Y`
    | in German…), from the plugin's language files.
    |
    */

    'date_format' => null,

    'datetime_format' => null,

];
