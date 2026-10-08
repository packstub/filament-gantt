<?php

namespace Packstub\Gantt\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Registers the chart's bar and create actions with Filament's action system, on
 * components that have one (panel pages and the widget do). Filament calls
 * cacheGanttActions() while booting, for every trait whose name ends in "Actions".
 *
 * The browser mounts them with the bar (`ganttRecord`) or the spot that was
 * clicked (`ganttStart`, `ganttEnd`, `ganttGroup`) as arguments; everything else is
 * resolved here again, through the chart's query and rules, whatever the browser sent.
 */
trait GanttActions
{
    /** The record a bar action is asked about while the bars are presented (see ganttActionsFor()). */
    protected ?Model $ganttActionRecord = null;

    public function cacheGanttActions(): void
    {
        $gantt = $this->getGantt();

        foreach ($gantt->getBarActions() as $action) {
            $record = fn () => $this->ganttActionRecord ?? $gantt->findRecord(static::ganttArgument($action, 'ganttRecord'));

            $action->record($record);

            // Refused for a bar that is not on the chart, and for one whose
            // Bar::actions() leaves this action out, whatever the browser asks. While
            // the bars are presented (ganttActionsFor()) only Filament's own visibility
            // is asked: presentBar() has the Bar's list already, and asking here would
            // run the app's bar() closure once more per action and bar.
            static::ganttChainHidden($action, fn () => $this->ganttActionRecord === null && (($found = $record()) === null || ! $gantt->offersAction($found, $action->getName())));
            $this->ganttRefreshAfter($action);

            $this->cacheAction($action);
        }

        // Each bar carries the actions this user may run on its record.
        if ($gantt->getBarActions() !== []) {
            $gantt->resolveActionsUsing(fn (Model $record) => $this->ganttActionsFor($record));
        }

        if ($action = $gantt->getCreateAction()) {
            if ($action->getModel(withDefault: false) === null) {
                $action->model($gantt->getModel());
            }

            // Hidden (so refused) unless the chart may create now, in that group.
            static::ganttChainHidden($action, fn () => ! $gantt->canCreateIn(static::ganttArgument($action, 'ganttGroup')));

            $prefill = fn () => $gantt->prefill(
                static::ganttArgument($action, 'ganttStart'),
                static::ganttArgument($action, 'ganttEnd'),
                static::ganttArgument($action, 'ganttGroup'),
            );

            // The form opens with the clicked spot's dates and group…
            $mount = static::ganttProperty($action, 'mountUsing');

            $action->mountUsing(function (?Schema $schema = null) use ($action, $mount, $prefill) {
                if ($mount) {
                    $action->evaluate($mount, ['schema' => $schema, 'form' => $schema]);
                } else {
                    $schema?->fill();
                }

                if ($schema && ($data = $prefill()) !== []) {
                    $schema->fill([...$schema->getRawState(), ...$data]);
                }
            });

            // …and a form without those fields still creates the record on that spot.
            // Only the attributes the form does not have at all: a field the user
            // left empty (a project Select, say) is their answer, not a gap to fill.
            $mutate = static::ganttProperty($action, 'mutateDataUsing');

            $action->mutateDataUsing(function (array $data) use ($action, $mutate, $prefill) {
                $data = $mutate ? $action->evaluate($mutate, ['data' => $data]) : $data;

                foreach ($prefill() as $attribute => $value) {
                    if (! array_key_exists($attribute, $data)) {
                        $data[$attribute] = $value;
                    }
                }

                return $data;
            });

            $this->ganttRefreshAfter($action);

            $this->cacheAction($action);
        }
    }

    /**
     * The bar actions visible to the user on a record: the action's own hidden() and
     * visible(), its authorization (on a resource page, the resource's policy) and
     * Bar::actions().
     *
     * @return list<string>
     */
    protected function ganttActionsFor(Model $record): array
    {
        $names = [];
        $this->ganttActionRecord = $record;

        try {
            foreach ($this->getGantt()->getBarActions() as $action) {
                if ($action->isVisible()) {
                    $names[] = $action->getName();
                }
            }
        } finally {
            $this->ganttActionRecord = null;
        }

        return $names;
    }

    /** Once the action has run, the browser reloads the chart (keeping its window and filters). */
    protected function ganttRefreshAfter(Action $action): void
    {
        $after = static::ganttProperty($action, 'after');

        $action->after(function () use ($action, $after) {
            $result = $after ? $action->evaluate($after) : null;

            $this->dispatch('packstub-gantt-refresh');

            return $result;
        });
    }

    /** Hide the action when $hidden says so, keeping whatever hidden() rule the app gave it. */
    protected static function ganttChainHidden(Action $action, Closure $hidden): void
    {
        $original = static::ganttProperty($action, 'isHidden');

        $action->hidden(fn () => $hidden() || (bool) $action->evaluate($original));
    }

    protected static function ganttProperty(Action $action, string $property): mixed
    {
        return (fn () => $this->{$property})->call($action);
    }

    /**
     * An argument the browser mounted the action with. The chart sends strings;
     * anything else (an array in a crafted request) counts as absent, so it never
     * reaches a typed parameter.
     */
    protected static function ganttArgument(Action $action, string $key): ?string
    {
        $value = $action->getArguments()[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
