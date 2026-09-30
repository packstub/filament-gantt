<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Axis
    |--------------------------------------------------------------------------
    |
    | The zoom level a chart opens with (day, week or month; a definition's
    | scale() wins), the ISO weekday the week columns start on (1 = Monday …
    | 7 = Sunday) and the weekdays shaded as weekend.
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
    | zoom level.
    |
    */

    'row_height' => 36,

    'px_per_day' => [
        'day' => 48,
        'week' => 18,
        'month' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Time window
    |--------------------------------------------------------------------------
    |
    | Without startDate()/endDate() a chart shows from `before` days ahead of
    | its earliest bar to `after` days past its latest one. Whatever asks for
    | it, a window is at least `min_window_days` and at most `max_window_days`
    | long.
    |
    */

    'window_padding_days' => [
        'before' => 7,
        'after' => 14,
    ],

    'min_window_days' => 28,

    'max_window_days' => 730,

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
    | Formats
    |--------------------------------------------------------------------------
    |
    | PHP date formats for the dates in tooltips and messages: whole days, and
    | records with a time.
    |
    */

    'date_format' => 'M j, Y',

    'datetime_format' => 'M j, Y H:i',

];
