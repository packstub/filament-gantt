<?php

namespace Packstub\Gantt\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Registers the chart's bar and create actions with Filament's action system, on
 * components that have one (panel pages and the widget do). Filament calls
 * cacheGanttActions() while booting, for every trait whose name ends in "Actions".
 *
 * The browser mounts them with the bar (`ganttRecord`) or the spot that was
 * clicked (`ganttStart`, `ganttEnd`, `ganttGroup`, `ganttLine` on a line's row
 * with lineBy(), `ganttRow` with rowBy()) as arguments; everything else is resolved here again, through the
 * chart's query and rules, whatever the browser sent.
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

            // An edit form that moves the record onto another piece of its line (or another bar of its row) is not saved.
            $mutate = static::ganttProperty($action, 'mutateDataUsing');

            $action->mutateDataUsing(function (array $data) use ($action, $mutate, $record) {
                $data = $mutate ? $action->evaluate($mutate, ['data' => $data]) : $data;

                $this->ganttGuardOverlap($action, $data, $record());

                return $data;
            });

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

            // Hidden (so refused) unless the chart may create now, in that group, on that line, in that row.
            static::ganttChainHidden($action, fn () => ! $gantt->canCreateIn(static::ganttArgument($action, 'ganttGroup'))
                || (($line = static::ganttArgument($action, 'ganttLine')) !== null && ! $gantt->canCreateOnLine($line))
                || (($row = static::ganttArgument($action, 'ganttRow')) !== null && ! $gantt->canCreateInRow($row, static::ganttArgument($action, 'ganttGroup'))));

            $prefill = fn () => $gantt->prefill(
                static::ganttArgument($action, 'ganttStart'),
                static::ganttArgument($action, 'ganttEnd'),
                static::ganttArgument($action, 'ganttGroup'),
                static::ganttArgument($action, 'ganttLine'),
                static::ganttArgument($action, 'ganttRow'),
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

                $this->ganttGuardOverlap($action, $data, null);

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

    /**
     * With preventLineOverlap() or preventRowOverlap(), a form whose dates (or line, or
     * row) would overlap another piece of the line or another bar of the row, and with
     * keepInsideParent() one whose dates leave its parent's (or a parent's that leave a
     * child out), is not saved: the modal stays open with the user's input, and a notification says why.
     * The form's values for the dates, the line and the row are laid over the record
     * being edited, or over a new one.
     *
     * @param  array<string, mixed>  $data
     */
    protected function ganttGuardOverlap(Action $action, array $data, ?Model $record): void
    {
        $gantt = $this->getGantt();
        $lines = $gantt->preventsLineOverlap();
        $rows = $gantt->preventsRowOverlap();
        $inside = $gantt->keepsInsideParent();

        if (! $lines && ! $rows && ! $inside) {
            return;
        }

        $attributes = array_filter([$gantt->getStartAttribute(), $gantt->getEndAttribute(), $gantt->getLineForeignKey(), $gantt->getRowForeignKey()]);
        $values = array_intersect_key($data, array_flip($attributes));

        if ($values === []) {
            return;
        }

        $model = $record ? clone $record : new ($gantt->getModel());
        $model->forceFill($values);

        $message = match (true) {
            $lines && $gantt->overlapsOnLine($model) => __('packstub-gantt::gantt.overlaps'),
            $rows && $gantt->overlapsOnRow($model) => __('packstub-gantt::gantt.row_overlaps'),
            // keepInsideParent(): a child inside its parent's dates, a parent around its children.
            $inside => $gantt->outsideParent($model),
            default => null,
        };

        if ($message !== null) {
            Notification::make()->danger()->title($message)->send();

            $action->halt();
        }
    }

    /**
     * Once the action has run, the browser reloads the chart (keeping its window and
     * filters). With writeSpanToParent(), the parent the record was under before the
     * action and the one it is under after it get their dates from their children.
     */
    protected function ganttRefreshAfter(Action $action): void
    {
        $before = static::ganttProperty($action, 'before');
        $after = static::ganttProperty($action, 'after');
        $parent = null;

        $action->before(function () use ($action, $before, &$parent) {
            $record = $this->getGantt()->writesSpanToParent() ? $action->getRecord() : null;
            $parent = $record instanceof Model ? $this->getGantt()->rowKey($record) : null;

            return $before ? $action->evaluate($before) : null;
        });

        $action->after(function () use ($action, $after, &$parent) {
            $result = $after ? $action->evaluate($after) : null;
            $gantt = $this->getGantt();

            if ($gantt->writesSpanToParent()) {
                $record = $action->getRecord();
                $gantt->writeParentSpans($parent, $record instanceof Model ? $gantt->rowKey($record) : null);
            }

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
