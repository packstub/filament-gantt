<?php

namespace Packstub\Gantt\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Packstub\Gantt\Exceptions\MoveRejected;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Gantt;

/**
 * The server side of a chart, for any Livewire component (the plugin's pages and
 * widget use it). Every call is renderless: the browser already shows the result,
 * so the server only answers with data and never re-renders the chart.
 */
trait InteractsWithGantt
{
    use GanttActions;

    protected ?Gantt $ganttDefinition = null;

    /**
     * Set once the chart's state has been rendered: later renders carry no state
     * (see getGanttConfig()). Set by the render that includes the chart, not by
     * every render: a chart behind a tab or a condition gets its bars when it
     * first appears.
     */
    #[Locked]
    public bool $ganttRendered = false;

    abstract public function gantt(Gantt $gantt): Gantt;

    public function getGantt(): Gantt
    {
        return $this->ganttDefinition ??= $this->gantt($this->makeGantt());
    }

    /**
     * The definition gantt() starts from: the defaults of this kind of component
     * (the widget is read-only, the resource page asks the resource's canEdit()).
     */
    protected function makeGantt(): Gantt
    {
        return Gantt::make()->key(static::class);
    }

    /**
     * The bars and groups of a window (the current one, or the one the toolbar asks
     * for), with the current filters and search. The server clamps the window and
     * answers with the one it settled on; with $withFilters, the filters' definitions
     * too (a config rendered without them); with $exact, a range typed in full is
     * shown as asked instead of stretched to `min_window_days`. Both flags count only
     * as `true`; the search term is read as a string or nothing.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    #[Renderless]
    public function ganttRefresh(mixed $filters = [], mixed $from = null, mixed $until = null, mixed $withFilters = false, mixed $exact = false, mixed $search = ''): array
    {
        $gantt = $this->getGantt();
        $state = $gantt->getState(is_array($filters) ? $filters : [], is_string($from) ? $from : null, is_string($until) ? $until : null, $exact === true, is_string($search) ? $search : '');

        if ($withFilters === true) {
            $state['filters'] = array_map(fn ($filter) => $filter->toArray(), $this->ganttUsableFilters());
        }

        return $state;
    }

    /**
     * The span of the bars the filters leave (`start`/`end` as `Y-m-d`), or null when
     * there are none: the window "Fit" asks for.
     *
     * @param  array<string, mixed>  $filters
     * @return array{start: string, end: string}|null
     */
    #[Renderless]
    public function ganttSpan(mixed $filters = [], mixed $search = ''): ?array
    {
        return $this->getGantt()->getSpan(is_array($filters) ? $filters : [], is_string($search) ? $search : '');
    }

    /**
     * The options of a select filter: its list, or the ones matching a search (a
     * searchable filter's live search), as the browser's select expects them.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Renderless]
    public function ganttFilterOptions(mixed $name, mixed $search = null): array
    {
        $filter = is_string($name) ? $this->ganttFilter($name) : null;

        if ($filter === null || ! ($filter->isSelect() || $filter->isBoolean()) || ($search !== null && ! is_string($search))) {
            return [];
        }

        $options = $search === null || $search === ''
            ? array_slice($filter->getOptions(), 0, $filter->getOptionsLimit(), preserve_keys: true)
            : $filter->getSearchResults($search);

        return collect($options)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])->values()->all();
    }

    /** The label of a select filter's value (one restored from the URL that the listed options do not hold). */
    #[Renderless]
    public function ganttFilterOptionLabel(mixed $name, mixed $value = null): ?string
    {
        $filter = is_string($name) ? $this->ganttFilter($name) : null;

        return $filter === null || ! ($filter->isSelect() || $filter->isBoolean()) || ! is_string($value) || $value === '' ? null : $filter->getOptionLabel($value);
    }

    /**
     * The labels of a multiple select filter's values (ones restored from the URL),
     * as the browser's select expects them; a value with no label is left out.
     *
     * @return list<array{value: string, label: string}>
     */
    #[Renderless]
    public function ganttFilterOptionLabels(mixed $name, mixed $values = []): array
    {
        $filter = is_string($name) ? $this->ganttFilter($name) : null;

        if ($filter === null || ! ($filter->isSelect() || $filter->isBoolean()) || ! is_array($values)) {
            return [];
        }

        $labels = [];

        foreach ($values as $value) {
            if ((is_string($value) && $value !== '') || is_int($value)) {
                $label = $filter->getOptionLabel($value);

                if ($label !== null) {
                    $labels[] = ['value' => (string) $value, 'label' => $label];
                }
            }
        }

        return $labels;
    }

    /**
     * The filters, checked once when they are sent to the browser: a toggle without
     * a query() is a mistake in the definition, told on the first render, not the
     * first time a user turns it on.
     *
     * @return list<Filter>
     */
    protected function ganttUsableFilters(): array
    {
        $filters = $this->getGantt()->getFilters();

        foreach ($filters as $filter) {
            $filter->assertUsable();
        }

        return $filters;
    }

    protected function ganttFilter(string $name): ?Filter
    {
        foreach ($this->getGantt()->getFilters() as $filter) {
            if ($filter->getName() === $name) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * A refused move answers with its reason. Anything else that goes wrong while
     * saving (a database error, a bug in moveUsing()) is reported and answered with
     * a generic message: an exception's text (a query with its bindings, say) is
     * for the log, never for a notification.
     *
     * @return array{ok: bool, bar?: array<string, mixed>, message?: string}
     */
    #[Renderless]
    public function ganttMove(mixed $id, mixed $start, mixed $end = null, mixed $group = null): array
    {
        // A crafted request with another shape is refused like any other, not a 500 in the log.
        if (! is_string($id) || ! is_string($start) || ($end !== null && ! is_string($end)) || ($group !== null && ! is_string($group))) {
            return ['ok' => false, 'message' => __('packstub-gantt::gantt.failed')];
        }

        try {
            $bar = $this->getGantt()->move($id, $start, $end, $group);
        } catch (MoveRejected $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => __('packstub-gantt::gantt.failed')];
        }

        $this->ganttMoved($id, $bar);

        return ['ok' => true, 'bar' => $bar];
    }

    /**
     * Hook after a successful move (log it, notify someone…).
     *
     * @param  array<string, mixed>  $bar
     */
    protected function ganttMoved(string $id, array $bar): void {}

    /**
     * Everything the browser needs to draw the chart. The state (window, groups,
     * bars) is queried and serialised the first time the chart is rendered only:
     * the chart element is wire:ignore, so a later render (an action's modal, a
     * `$refresh`) never reaches it; the browser keeps its own state and reloads it
     * renderless. Should a state-less config ever reach a fresh chart (a lazy
     * widget, a component restored without its flag), the browser asks for the
     * state itself.
     *
     * @return array<string, mixed>
     */
    public function getGanttConfig(?bool $withState = null): array
    {
        $gantt = $this->getGantt();

        if ($withState = $withState ?? ! $this->ganttRendered) {
            $state = $gantt->getState();
            $this->ganttRendered = true;
        } else {
            $state = ['window' => ['start' => null, 'end' => null], 'groups' => [], 'bars' => [], 'total' => 0];
        }

        // Bar and create actions need Filament's action system on the component.
        $actions = $this instanceof HasActions;
        $create = $actions && $gantt->canCreate() ? $gantt->getCreateAction() : null;
        $user = Filament::getCurrentPanel() ? Filament::auth()->id() : auth()->id();

        return [
            'key' => 'gantt:'.($gantt->getKey() ?? static::class).':'.($user ?? 'guest'),
            'window' => $state['window'],
            'groups' => $state['groups'],
            'bars' => $state['bars'],
            'total' => $state['total'],
            'grouped' => $gantt->isGrouped(),
            'compact' => $gantt->isCompact(),
            'scale' => $gantt->getScale()->value,
            'unit' => $gantt->getUnit()->value,
            'minMinutes' => $gantt->getMinMinutes(),
            'maxMinutes' => $gantt->getMaxMinutes(),
            'scales' => array_map(fn ($scale) => $scale->value, $gantt->getScales()),
            'firstDayOfWeek' => $gantt->getFirstDayOfWeek(),
            'weekendDays' => array_values(array_map('intval', (array) config('filament-gantt.weekend_days', [6, 7]))),
            'rowHeight' => max(24, (int) config('filament-gantt.row_height', 36)),
            'pxPerDay' => [
                'hour' => (float) config('filament-gantt.px_per_day.hour', 960),
                'day' => (float) config('filament-gantt.px_per_day.day', 48),
                'week' => (float) config('filament-gantt.px_per_day.week', 18),
                'month' => (float) config('filament-gantt.px_per_day.month', 5),
                'year' => (float) config('filament-gantt.px_per_day.year', 1.5),
            ],
            'today' => now()->toDateString(),
            'now' => now()->format('Y-m-d H:i'),
            'locale' => str_replace('_', '-', app()->getLocale()),
            'dateFormat' => Gantt::dateFormat(),
            'dateTimeFormat' => Gantt::dateTimeFormat(),
            'readOnly' => $gantt->isReadOnly(),
            'height' => $this->getGanttHeight(),
            'searchable' => $gantt->isSearchable(),
            'filtersLayout' => $gantt->getFiltersLayout()->value,
            // Whether the view draws the filters' home at all (their definitions may come later, see below).
            'hasFilters' => $gantt->getFilters() !== [],
            // Filter options are often queries: a render after the first one (the chart
            // is wire:ignore) does without them, and a chart that is new on such a render
            // asks for them along with its state.
            'filters' => $withState ? array_map(fn ($f) => $f->toArray(), $this->ganttUsableFilters()) : null,
            'refreshOn' => $gantt->getRefreshOn(),
            'poll' => $gantt->getPoll(),
            'barActions' => array_map(fn ($action) => $this->ganttActionConfig($action), $actions ? $gantt->getBarActions() : []),
            'barAction' => $gantt->getBarAction(),
            // Offered only when the user may run it (its hidden(), its authorization).
            'createAction' => $create && $create->isVisible()
                ? ['name' => $create->getName(), 'label' => $create->getLabel()]
                : null,
            'i18n' => __('packstub-gantt::gantt'),
        ];
    }

    /**
     * How a bar action looks in the menu. Read without a record: a label, icon or
     * colour closure that needs one falls back to the action's name.
     *
     * @return array{name: string, label: string, icon: ?string, color: ?string}
     */
    protected function ganttActionConfig(Action $action): array
    {
        $read = function (Closure $read, mixed $fallback) {
            try {
                return $read();
            } catch (\Throwable) {
                return $fallback;
            }
        };

        return [
            'name' => $action->getName(),
            'label' => (string) $read(fn () => $action->getLabel(), Str::headline($action->getName())),
            'icon' => $read(fn () => ($icon = $action->getIcon() ?? $action->getGroupedIcon()) ? \Filament\Support\generate_icon_html($icon)?->toHtml() : null, null),
            'color' => $read(fn () => is_string($color = $action->getColor()) ? $color : null, null),
        ];
    }

    /** A CSS height for the chart; null fits it to the window below its top edge. */
    protected function getGanttHeight(): ?string
    {
        return null;
    }
}
