<?php

namespace Packstub\Gantt\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Schema;

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
    public function cacheGanttActions(): void
    {
        $gantt = $this->getGantt();

        foreach ($gantt->getBarActions() as $action) {
            $action->record(fn () => $gantt->findRecord($action->getArguments()['ganttRecord'] ?? null));

            static::ganttChainHidden($action, fn () => $gantt->findRecord($action->getArguments()['ganttRecord'] ?? null) === null);
            $this->ganttRefreshAfter($action);

            $this->cacheAction($action);
        }

        if ($action = $gantt->getCreateAction()) {
            if ($action->getModel(withDefault: false) === null) {
                $action->model($gantt->getModel());
            }

            // Hidden (so refused) unless the chart may create now, in that group.
            static::ganttChainHidden($action, fn () => ! $gantt->canCreateIn($action->getArguments()['ganttGroup'] ?? null));

            $prefill = fn () => $gantt->prefill(
                $action->getArguments()['ganttStart'] ?? null,
                $action->getArguments()['ganttEnd'] ?? null,
                $action->getArguments()['ganttGroup'] ?? null,
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
}
