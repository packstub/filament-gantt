<?php

namespace Packstub\Gantt\Concerns;

use Filament\Actions\Contracts\HasActions;
use Livewire\Attributes\Renderless;
use Packstub\Gantt\Exceptions\MoveRejected;
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

    abstract public function gantt(Gantt $gantt): Gantt;

    public function getGantt(): Gantt
    {
        return $this->ganttDefinition ??= $this->gantt(Gantt::make()->key(static::class));
    }

    /**
     * The bars and groups of the current window, with the current filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array{window: array{start: string, end: string}, groups: list<array<string, mixed>>, bars: list<array<string, mixed>>}
     */
    #[Renderless]
    public function ganttRefresh(array $filters = [], ?string $from = null, ?string $until = null): array
    {
        return $this->getGantt()->getState($filters, $from, $until);
    }

    /**
     * Another time window (the date range in the toolbar). The server clamps it
     * and answers with the window it settled on.
     *
     * @param  array<string, mixed>  $filters
     * @return array{window: array{start: string, end: string}, groups: list<array<string, mixed>>, bars: list<array<string, mixed>>}
     */
    #[Renderless]
    public function ganttWindow(?string $from, ?string $until, array $filters = []): array
    {
        return $this->getGantt()->getState($filters, $from, $until);
    }

    /**
     * @return array{ok: bool, bar?: array<string, mixed>, message?: string}
     */
    #[Renderless]
    public function ganttMove(string $id, string $start, ?string $end = null): array
    {
        try {
            $bar = $this->getGantt()->move($id, $start, $end);
        } catch (MoveRejected $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
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

    /** @return array<string, mixed> */
    public function getGanttConfig(): array
    {
        $gantt = $this->getGantt();
        $state = $gantt->getState();

        // Bar and create actions need Filament's action system on the component.
        $actions = $this instanceof HasActions;

        return [
            'key' => 'gantt:'.($gantt->getKey() ?? static::class).':'.(auth()->id() ?? 'guest'),
            'window' => $state['window'],
            'groups' => $state['groups'],
            'bars' => $state['bars'],
            'grouped' => $gantt->isGrouped(),
            'compact' => $gantt->isCompact(),
            'scale' => $gantt->getScale()->value,
            'unit' => $gantt->getUnit()->value,
            'firstDayOfWeek' => $gantt->getFirstDayOfWeek(),
            'weekendDays' => array_values(array_map('intval', (array) config('filament-gantt.weekend_days', [6, 7]))),
            'rowHeight' => max(24, (int) config('filament-gantt.row_height', 36)),
            'pxPerDay' => [
                'day' => (int) config('filament-gantt.px_per_day.day', 48),
                'week' => (int) config('filament-gantt.px_per_day.week', 18),
                'month' => (int) config('filament-gantt.px_per_day.month', 5),
            ],
            'today' => now()->toDateString(),
            'locale' => str_replace('_', '-', app()->getLocale()),
            'dateFormat' => (string) config('filament-gantt.date_format', 'M j, Y'),
            'dateTimeFormat' => (string) config('filament-gantt.datetime_format', 'M j, Y H:i'),
            'readOnly' => $gantt->isReadOnly(),
            'height' => $this->getGanttHeight(),
            'filters' => array_map(fn ($f) => $f->toArray(), $gantt->getFilters()),
            'refreshOn' => $gantt->getRefreshOn(),
            'poll' => $gantt->getPoll(),
            'barActions' => array_map(fn ($action) => [
                'name' => $action->getName(),
                'label' => $action->getLabel(),
                'icon' => ($icon = $action->getIcon() ?? $action->getGroupedIcon()) ? \Filament\Support\generate_icon_html($icon)?->toHtml() : null,
                'color' => is_string($color = $action->getColor()) ? $color : null,
            ], $actions ? $gantt->getBarActions() : []),
            'barAction' => $gantt->getBarAction(),
            'createAction' => $actions && $gantt->canCreate() && ($create = $gantt->getCreateAction())
                ? ['name' => $create->getName(), 'label' => $create->getLabel()]
                : null,
            'i18n' => __('packstub-gantt::gantt'),
        ];
    }

    /** A CSS height for the chart; null fits it to the window below its top edge. */
    protected function getGanttHeight(): ?string
    {
        return null;
    }
}
