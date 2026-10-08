# Styling

The stylesheet is plain CSS on Filament's colour variables (`--gray-*`, `--primary-*`, `--danger-*`) and follows the panel's theme and dark mode without compiling anything. Restyle it with the `--ps-gantt-*` variables on `.ps-gantt` from your theme, prefixed so they win over the plugin's stylesheet:

```css
.fi-body .ps-gantt {
    --ps-gantt-label-width: 18rem;   /* the sticky label column */
    --ps-gantt-radius: 0.75rem;
    --ps-gantt-bar-radius: 0.5rem;
    --ps-gantt-today: var(--danger-500);  /* the today line; the panel's primary colour by default */
    --ps-gantt-arrow: var(--gray-500);    /* dependency arrows, and the dashed late ones: */
    --ps-gantt-arrow-late: var(--warning-500);
    --ps-gantt-weekend: rgb(0 0 0 / 0.03);
    --ps-gantt-height: 40rem;        /* the most the chart grows to; pages fit it to the window by default */
}
```

| Variable | What it sets |
| --- | --- |
| `--ps-gantt-bg`, `--ps-gantt-surface`, `--ps-gantt-border`, `--ps-gantt-line` | Backgrounds, borders and grid lines. |
| `--ps-gantt-text`, `--ps-gantt-muted`, `--ps-gantt-soft`, `--ps-gantt-bar-text` | Text colours. |
| `--ps-gantt-weekend`, `--ps-gantt-group`, `--ps-gantt-today`, `--ps-gantt-ring` | Weekend shading, group rows, the today line, focus rings. |
| `--ps-gantt-label-width`, `--ps-gantt-height`, `--ps-gantt-radius`, `--ps-gantt-bar-radius` | Sizes. |

Row height and pixels per day are numbers the script needs: set them in `config/filament-gantt.php` (`row_height`, `px_per_day`).

The chart is as tall as its rows, up to `--ps-gantt-height`; it scrolls past that.

Each bar carries its colour as `--ps-bar`; the stylesheet draws a tinted background, a solid start edge and the progress fill from it. A label too long for its bar is drawn just after it (`ps-gantt-bar-outside`), except in the compact layout, where the next bar of the lane may sit there. A bar narrower than its resize handles has none (`ps-gantt-bar-narrow`). Classes are prefixed `ps-gantt-` (`ps-gantt-bar`, `ps-gantt-bar-milestone`, `ps-gantt-row-group`…) for your own rules.

## Dark mode

Handled: the variables are redefined under `.dark .ps-gantt`. Override them there for your own dark palette.

## Right-to-left

The chart uses logical properties (`inset-inline-start`, `margin-inline-end`…) throughout, so under `dir="rtl"` the label column sits on the right, the axis runs right-to-left and a drag follows the pointer the right way.

## Translations

Every string is in `resources/lang/{en,ro,ru,de}/gantt.php`. Publish them to change or add a language:

```bash
php artisan vendor:publish --tag=packstub-gantt-translations
```

Month and weekday names on the axis come from the browser's `Intl` in the panel's locale. The date formats of tooltips and messages are PHP formats from the language files (`M j, Y` in English, `j. M Y` in German, `j M Y` in Romanian and Russian), unless the config sets `date_format` and `datetime_format` for every locale. Counts use the language's plural forms.
