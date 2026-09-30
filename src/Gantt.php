<?php

namespace Packstub\Gantt;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Traits\Conditionable;
use Packstub\Gantt\Enums\Scale;
use Packstub\Gantt\Enums\Unit;
use Packstub\Gantt\Events\BarMoved;
use Packstub\Gantt\Exceptions\MoveRejected;

/**
 * The chart definition: which records, which attributes hold the dates, how a bar
 * looks, how rows are grouped and what a move may do. The page renders it once as
 * JSON; after that the browser owns the chart and only asks the server to move a
 * bar, refresh, or load another time window.
 *
 * Dates: the chart works in half-open ranges [start, end). A date-only record
 * (a `date` cast) ending on the 7th is drawn through the whole 7th, so its chart
 * end is the 8th at 00:00; the record is written back the same way. A datetime
 * record's end is used as is. A milestone (null end, or a datetime end equal to
 * its start) has start == end.
 *
 * Time zones: the chart is naive. Every moment it handles (a record's dates, what
 * the browser sends, lockedBefore(), the window) is read as a wall-clock time and
 * tagged UTC, so a duration is the same number of minutes on both sides of a
 * daylight-saving change and a whole day is always 1440 minutes. The closures
 * (canMove(), moveUsing(), afterMove()) and BarMoved receive those UTC-tagged
 * moments; format them, or compare them, do not convert them.
 */
class Gantt
{
    use Conditionable;

    protected Builder|Closure|null $query = null;

    protected string $startAttribute = 'starts_at';

    protected string $endAttribute = 'ends_at';

    protected ?bool $dateOnly = null;

    protected string|Closure|null $label = null;

    protected string|Closure|null $color = null;

    protected string|Closure|null $progress = null;

    protected string|Closure|null $milestone = null;

    protected ?Closure $bar = null;

    protected ?string $groupAttribute = null;

    protected string|Closure|null $groupLabel = null;

    /** @var list<Group>|Closure|null */
    protected array|Closure|null $groups = null;

    protected bool $compact = false;

    /** @var array{0: string, 1: string}|null */
    protected ?array $sort = null;

    protected ?Scale $scale = null;

    protected Unit $unit = Unit::Day;

    protected ?int $firstDayOfWeek = null;

    protected ?CarbonImmutable $windowStart = null;

    protected ?CarbonImmutable $windowEnd = null;

    protected bool|Closure $movable = true;

    protected bool|Closure $resizable = true;

    protected bool|Closure $readOnly = false;

    protected ?int $minMinutes = null;

    protected ?int $maxMinutes = null;

    protected CarbonInterface|Closure|null $lockedBefore = null;

    protected ?Closure $canMove = null;

    protected ?Closure $moveUsing = null;

    protected ?Closure $afterMove = null;

    /** @var list<Filter> */
    protected array $filters = [];

    /** @var list<Action> */
    protected array $barActions = [];

    protected ?string $barAction = null;

    protected ?Action $createAction = null;

    protected ?Closure $creatableIn = null;

    /** @var list<string> */
    protected array $refreshOn = [];

    protected ?int $poll = null;

    protected ?string $key = null;

    /** @var array<string, Model|null> */
    protected array $records = [];

    /** @var array<string, string> labels of the groups met while reading, by key */
    protected array $groupLabels = [];

    public static function make(): static
    {
        return new static;
    }

    /* ------------------------------------------------------------------ configuration */

    /** The records on the chart. Scope it here (tenant, project…): every read and every move goes through it. */
    public function query(Builder|Closure $query): static
    {
        $this->query = $query;

        return $this;
    }

    /** The attribute holding a bar's start: a `date` or `datetime` cast, or a plain string column. */
    public function start(string $attribute): static
    {
        $this->startAttribute = $attribute;

        return $this;
    }

    /** The attribute holding a bar's end. Null means a milestone. */
    public function end(string $attribute): static
    {
        $this->endAttribute = $attribute;

        return $this;
    }

    /**
     * Whether the dates are whole days (the end day is included in the bar). By
     * default a `date` cast, or a plain `Y-m-d` value, means whole days.
     */
    public function dateOnly(?bool $condition = true): static
    {
        $this->dateOnly = $condition;

        return $this;
    }

    /** The bar's text: an attribute name or `fn (Model $record): string`. */
    public function label(string|Closure|null $attribute): static
    {
        $this->label = $attribute;

        return $this;
    }

    /** An attribute holding a colour (or an enum implementing HasColor), or `fn (Model $record): ?string`. */
    public function color(string|Closure|null $attribute): static
    {
        $this->color = $attribute;

        return $this;
    }

    /** An attribute holding 0–100, or `fn (Model $record): ?int`. */
    public function progress(string|Closure|null $attribute): static
    {
        $this->progress = $attribute;

        return $this;
    }

    /**
     * What makes a bar a milestone (a diamond at its start, not resizable). Default:
     * a null end, or a datetime end equal to the start. An attribute name or
     * `fn (Model $record): bool`.
     */
    public function milestone(string|Closure|null $attribute): static
    {
        $this->milestone = $attribute;

        return $this;
    }

    /**
     * Shape a bar fully: `fn (Model $record): Bar`. What it sets wins over label(),
     * color() and progress().
     */
    public function bar(?Closure $callback): static
    {
        $this->bar = $callback;

        return $this;
    }

    /**
     * Rows grouped by a column (`status`, `assignee_id`) or a `BelongsTo` relation
     * (`project`); the label is a dotted path (`project.name`) or
     * `fn (Model $record): string`. Without groups() the groups appear in the order
     * of the first bar of each.
     */
    public function groupBy(?string $attribute, string|Closure|null $label = null): static
    {
        $this->groupAttribute = $attribute;
        $this->groupLabel = $label;

        return $this;
    }

    /**
     * The groups, in order, empty ones included: a list of Group (see
     * Group::fromModels()) or a closure returning one.
     *
     * @param  list<Group>|Closure|null  $groups
     */
    public function groups(array|Closure|null $groups): static
    {
        $this->groups = $groups;

        return $this;
    }

    /**
     * Resource-timeline layout: the bars of a group share one row (a room's
     * bookings, a machine's jobs), overlapping ones stack. Default: one row per bar
     * under a collapsible group row.
     */
    public function compact(bool $condition = true): static
    {
        $this->compact = $condition;

        return $this;
    }

    /** Order of the bars inside a group (default: by start). */
    public function sortBy(string $column, string $direction = 'asc'): static
    {
        $this->sort = [$column, strtolower($direction) === 'desc' ? 'desc' : 'asc'];

        return $this;
    }

    /** The zoom level the chart opens with (the user can change it). Default: the config's. */
    public function scale(Scale|string|null $scale): static
    {
        $this->scale = is_string($scale) ? Scale::fromName($scale) : $scale;

        return $this;
    }

    /** What a drag snaps to: whole days (default) or hours, for datetime records. */
    public function snapTo(Unit|string $unit): static
    {
        $this->unit = $unit instanceof Unit ? $unit : (Unit::tryFrom($unit) ?? Unit::Day);

        return $this;
    }

    /** ISO weekday the week columns start on (1 = Monday … 7 = Sunday). Default: the config's. */
    public function firstDayOfWeek(?int $day): static
    {
        $this->firstDayOfWeek = $day === null ? null : max(1, min(7, $day));

        return $this;
    }

    /** The first day shown. Default: a week before the earliest bar. */
    public function startDate(CarbonInterface|string|null $date): static
    {
        $this->windowStart = $this->toCarbon($date)?->startOfDay();

        return $this;
    }

    /** The last day shown. Default: two weeks after the latest bar. */
    public function endDate(CarbonInterface|string|null $date): static
    {
        $this->windowEnd = $this->toCarbon($date)?->startOfDay();

        return $this;
    }

    /** Whether a bar may be dragged to another date: a bool or `fn (Model $record): bool`. */
    public function movable(bool|Closure $condition = true): static
    {
        $this->movable = $condition;

        return $this;
    }

    /** Whether a bar's edges may be dragged: a bool or `fn (Model $record): bool`. */
    public function resizable(bool|Closure $condition = true): static
    {
        $this->resizable = $condition;

        return $this;
    }

    /** Show only: nothing moves, nothing resizes, nothing is created. The widget's default. */
    public function readOnly(bool|Closure $condition = true): static
    {
        $this->readOnly = $condition;

        return $this;
    }

    /** The shortest a bar may be resized to (default: one snap unit). */
    public function minDuration(int $days = 0, int $hours = 0, int $minutes = 0): static
    {
        $this->minMinutes = max(0, $days * 1440 + $hours * 60 + $minutes) ?: null;

        return $this;
    }

    /** The longest a bar may be resized to. */
    public function maxDuration(int $days = 0, int $hours = 0, int $minutes = 0): static
    {
        $this->maxMinutes = max(0, $days * 1440 + $hours * 60 + $minutes) ?: null;

        return $this;
    }

    /**
     * Nothing may be moved to before this moment, and a bar that started before it
     * keeps its start (its end may still change, as long as it stays after it).
     * `lockedBefore(today())` keeps the past as it happened.
     */
    public function lockedBefore(CarbonInterface|Closure|null $moment): static
    {
        $this->lockedBefore = $moment;

        return $this;
    }

    /**
     * A last check before a move is saved: `fn (Model $record, CarbonImmutable $start,
     * CarbonImmutable $end, CarbonImmutable $previousStart, CarbonImmutable $previousEnd): bool|string`.
     * Return false to refuse, or a string to refuse with that message.
     */
    public function canMove(?Closure $callback): static
    {
        $this->canMove = $callback;

        return $this;
    }

    /**
     * What a move does: `fn (Model $record, CarbonImmutable $start, CarbonImmutable $end,
     * CarbonImmutable $previousStart, CarbonImmutable $previousEnd): void`. Throw to refuse
     * it. Default: write the start and end attributes and save. The dates are the
     * chart's half-open range; a date-only record's end day is `$end->subDay()`.
     */
    public function moveUsing(?Closure $callback): static
    {
        $this->moveUsing = $callback;

        return $this;
    }

    /** Runs after a move is saved, with the same arguments as moveUsing(). */
    public function afterMove(?Closure $callback): static
    {
        $this->afterMove = $callback;

        return $this;
    }

    /** @param  list<Filter>  $filters */
    public function filters(array $filters): static
    {
        $this->filters = array_values($filters);

        return $this;
    }

    /**
     * Filament actions offered on each bar (right-click, or the ⋯ button). The bar's
     * record is the action's record, resolved through the chart's query.
     *
     * @param  list<Action>  $actions
     */
    public function barActions(array $actions): static
    {
        $this->barActions = array_values($actions);

        return $this;
    }

    /**
     * The action a click on a bar runs: the name of one of barActions(), an Action
     * (added to them), or an Action class (`EditAction::class`).
     */
    public function barAction(string|Action|null $action): static
    {
        if (is_string($action) && class_exists($action) && is_subclass_of($action, Action::class)) {
            $action = $action::make();
        }

        if ($action instanceof Action) {
            $this->barActions[] = $action;
            $action = $action->getName();
        }

        $this->barAction = $action;

        return $this;
    }

    /**
     * A create action (Filament's CreateAction, with your form) run by a click on an
     * empty spot: the form opens with the start, the end and the group of that spot.
     */
    public function createAction(?Action $action): static
    {
        $this->createAction = $action;

        return $this;
    }

    /**
     * Which groups a click may create in: `fn (string $group): bool`. By default a
     * group the chart knows: one listed in groups(), one a bar of the chart is in,
     * or (for a `BelongsTo` group) a related record that exists. The group is
     * written to the new record only when this says yes; a form field for the
     * foreign key (`Select::make('project_id')->relationship(...)`) stays the place
     * to scope it to the tenant.
     */
    public function creatableIn(?Closure $callback): static
    {
        $this->creatableIn = $callback;

        return $this;
    }

    /**
     * Livewire events after which the chart reloads its bars (`$this->dispatch('task-updated')`
     * anywhere on the page).
     *
     * @param  list<string>  $events
     */
    public function refreshOn(array $events): static
    {
        $this->refreshOn = array_values($events);

        return $this;
    }

    /** Refresh from the server every so often (`'10s'`, `'1m'` or milliseconds), while nobody is dragging. */
    public function poll(string|int|null $interval = '10s'): static
    {
        if ($interval === null) {
            $this->poll = null;
        } elseif (is_int($interval)) {
            $this->poll = max(1000, $interval);
        } else {
            preg_match('/^(\d+)\s*(ms|s|m)?$/', trim($interval), $m)
                ?: throw new \InvalidArgumentException("Cannot read the poll interval [{$interval}]: use '10s', '1m' or milliseconds.");
            $this->poll = max(1000, (int) $m[1] * match ($m[2] ?? 'ms') {
                's' => 1000, 'm' => 60000, default => 1
            });
        }

        return $this;
    }

    /** Where the browser remembers collapsed groups and the zoom; defaults to the page's class. */
    public function key(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /* ------------------------------------------------------------------ reading */

    public function getStartAttribute(): string
    {
        return $this->startAttribute;
    }

    public function getEndAttribute(): string
    {
        return $this->endAttribute;
    }

    public function getGroupAttribute(): ?string
    {
        return $this->groupAttribute;
    }

    /** The attribute a new record's group is written to: the column, or a BelongsTo's foreign key. */
    public function getGroupForeignKey(): ?string
    {
        if ($this->groupAttribute === null) {
            return null;
        }

        $model = $this->newModel();

        if (! $model->isRelation($this->groupAttribute)) {
            return $this->groupAttribute;
        }

        $relation = $model->{$this->groupAttribute}();

        return $relation instanceof BelongsTo ? $relation->getForeignKeyName() : null;
    }

    public function isGrouped(): bool
    {
        return $this->groupAttribute !== null || $this->groups !== null;
    }

    public function isCompact(): bool
    {
        return $this->compact;
    }

    public function getScale(): Scale
    {
        return $this->scale ?? Scale::fromName(config('filament-gantt.default_scale'));
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function getFirstDayOfWeek(): int
    {
        return $this->firstDayOfWeek ?? max(1, min(7, (int) config('filament-gantt.first_day_of_week', 1)));
    }

    public function isReadOnly(): bool
    {
        return (bool) ($this->readOnly instanceof Closure ? app()->call($this->readOnly) : $this->readOnly);
    }

    public function isMovable(Model $record): bool
    {
        return ! $this->isReadOnly() && (bool) $this->evaluate($this->movable, $record);
    }

    public function isResizable(Model $record): bool
    {
        return ! $this->isReadOnly() && (bool) $this->evaluate($this->resizable, $record);
    }

    public function getMinMinutes(): int
    {
        return $this->minMinutes ?? $this->unit->minutes();
    }

    public function getMaxMinutes(): ?int
    {
        return $this->maxMinutes;
    }

    public function getLockedBefore(): ?CarbonImmutable
    {
        $moment = $this->lockedBefore instanceof Closure ? app()->call($this->lockedBefore) : $this->lockedBefore;

        return $moment === null ? null : CarbonImmutable::instance(Date::parse($moment))->shiftTimezone('UTC');
    }

    /** @return list<Filter> */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /** @return list<Action> */
    public function getBarActions(): array
    {
        return $this->barActions;
    }

    public function getBarAction(): ?string
    {
        return $this->barAction;
    }

    public function getCreateAction(): ?Action
    {
        return $this->createAction;
    }

    /** Whether a new bar may be created from a click now. */
    public function canCreate(): bool
    {
        return $this->createAction !== null && ! $this->isReadOnly();
    }

    /**
     * Whether a new bar may be created in a group now (null or '' is the ungrouped
     * row). Checked on the server whatever the browser sent: an unknown key never
     * reaches the record.
     */
    public function canCreateIn(mixed $group): bool
    {
        if (! $this->canCreate()) {
            return false;
        }

        if ($group === null || $group === '') {
            return true;
        }

        if (! is_string($group) && ! is_int($group)) {
            return false;
        }

        $group = (string) $group;

        if ($this->creatableIn) {
            return (bool) ($this->creatableIn)($group);
        }

        return $this->knowsGroup($group);
    }

    /** @return list<string> */
    public function getRefreshOn(): array
    {
        return $this->refreshOn;
    }

    public function getPoll(): ?int
    {
        return $this->poll ?? (($ms = config('filament-gantt.polling_interval')) ? max(1000, (int) $ms) : null);
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    /** @return class-string<Model> */
    public function getModel(): string
    {
        return $this->newModel()::class;
    }

    /** The record behind a bar, if it is still on this chart for this user. */
    public function findRecord(string|int|null $id): ?Model
    {
        if (blank($id)) {
            return null;
        }

        return array_key_exists($key = (string) $id, $this->records)
            ? $this->records[$key]
            : $this->records[$key] = $this->baseQuery()->whereKey($key)->first();
    }

    /**
     * The days shown, as whole days [start, end] inclusive. The browser may ask for
     * its own window; the definition's startDate()/endDate() come next; otherwise a
     * padded span around the bars. Always at least `min_window_days` and at most
     * `max_window_days` long.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function getWindow(?string $from = null, ?string $until = null): array
    {
        $start = Filter::date($from) ? CarbonImmutable::parse($from, 'UTC')->startOfDay() : $this->windowStart;
        $end = Filter::date($until) ? CarbonImmutable::parse($until, 'UTC')->startOfDay() : $this->windowEnd;

        if ($start === null || $end === null) {
            [$min, $max] = $this->span();
            $start ??= $min->subDays((int) config('filament-gantt.window_padding_days.before', 7));
            $end ??= $max->addDays((int) config('filament-gantt.window_padding_days.after', 14));
        }

        $minDays = max(7, (int) config('filament-gantt.min_window_days', 28));
        $maxDays = max($minDays, (int) config('filament-gantt.max_window_days', 730));

        if ($end < $start) {
            $end = $start;
        }

        $days = (int) $start->diffInDays($end) + 1;

        if ($days < $minDays) {
            $end = $start->addDays($minDays - 1);
        } elseif ($days > $maxDays) {
            $end = $start->addDays($maxDays - 1);
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Everything the browser needs to draw the chart for a window.
     *
     * @param  array<string, mixed>  $filters
     * @return array{window: array{start: string, end: string}, groups: list<array<string, mixed>>, bars: list<array<string, mixed>>}
     */
    public function getState(array $filters = [], ?string $from = null, ?string $until = null): array
    {
        $window = $this->getWindow($from, $until);
        $bars = $this->getBars($filters, $window['start'], $window['end']);

        return [
            'window' => ['start' => $window['start']->toDateString(), 'end' => $window['end']->toDateString()],
            'groups' => $this->getGroups($bars),
            'bars' => $bars,
        ];
    }

    /**
     * The bars overlapping a window (all of them without one), in the chart's order.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getBars(array $filters = [], ?CarbonInterface $from = null, ?CarbonInterface $until = null): array
    {
        $query = $this->filteredQuery($filters);
        $start = $query->qualifyColumn($this->startAttribute);
        $end = $query->qualifyColumn($this->endAttribute);

        $query->whereNotNull($start);

        if ($from !== null && $until !== null) {
            // Overlapping the window: starts before the day after its last day, and
            // ends on or after its first day (a milestone has no end: its start
            // decides). Plain ranges, never DATE(column): the columns' indexes stay in use.
            $first = $from->toDateString();
            $after = $until->toImmutable()->addDay()->toDateString();

            $query
                ->where($start, '<', $after)
                ->where(fn (Builder $q) => $q
                    ->where($end, '>=', $first)
                    ->orWhere(fn (Builder $m) => $m->whereNull($end)->where($start, '>=', $first)));
        }

        [$column, $direction] = $this->sort ?? [$this->startAttribute, 'asc'];

        return $query
            ->orderBy($query->qualifyColumn($column), $direction)
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->get()
            ->map(fn (Model $record) => $this->presentBar($record))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The groups in order: the definition's, plus any met on a bar that was not
     * listed, or the bars' in order of first appearance.
     *
     * @param  list<array<string, mixed>>  $bars
     * @return list<array<string, mixed>>
     */
    public function getGroups(array $bars): array
    {
        $groups = [];

        foreach ($this->definedGroups() as $group) {
            $groups[$group->getKey()] = $group->toArray();
        }

        foreach ($bars as $bar) {
            $key = (string) ($bar['group'] ?? '');

            if (! array_key_exists($key, $groups)) {
                $groups[$key] = Group::make($key)->label($this->groupLabels[$key] ?? null)->toArray();
            }
        }

        if ($groups === [] && ! $this->isGrouped()) {
            $groups[''] = Group::make('')->toArray();
        }

        $counts = array_count_values(array_map(fn ($bar) => (string) ($bar['group'] ?? ''), $bars));

        return array_values(array_map(fn (array $group) => [...$group, 'count' => $counts[$group['key']] ?? 0], $groups));
    }

    /** @return array<string, mixed>|null null when the record has no start */
    public function presentBar(Model $record): ?array
    {
        $span = $this->span($record);

        if ($span === null) {
            return null;
        }

        [$start, $end, $milestone] = $span;

        $bar = Bar::make()
            ->label($this->label === null ? (string) $record->getKey() : (string) $this->read($this->label, $record))
            ->color($this->color === null ? null : $this->read($this->color, $record))
            ->progress($this->progress === null ? null : $this->read($this->progress, $record));

        $shape = $bar->toArray();

        if ($this->bar && ($custom = ($this->bar)($record)) instanceof Bar) {
            $shape = [...$shape, ...$custom->toArray()];
        }

        $group = $this->groupKey($record);

        if ($group !== null && ! isset($this->groupLabels[$group])) {
            $this->groupLabels[$group] = $this->groupLabelFor($record, $group);
        }

        return [
            'id' => (string) $record->getKey(),
            'group' => $group ?? '',
            'start' => $start->format('Y-m-d H:i'),
            'end' => $end->format('Y-m-d H:i'),
            'allDay' => $this->isDateOnly($record),
            'milestone' => $milestone,
            'movable' => $this->isMovable($record),
            'resizable' => ! $milestone && $this->isResizable($record),
            ...$shape,
        ];
    }

    /* ------------------------------------------------------------------ writing */

    /**
     * Move or resize a bar, enforcing every rule on the server whatever the browser
     * claimed. Dates are the chart's half-open range (`Y-m-d H:i`, or anything
     * Carbon parses); without an end the duration is kept. Returns the bar as it
     * looks afterwards.
     *
     * @return array<string, mixed>
     *
     * @throws MoveRejected
     */
    public function move(string $id, string $start, ?string $end = null): array
    {
        $record = $this->findRecord($id)
            ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));

        if ($this->isReadOnly()) {
            throw new MoveRejected(__('packstub-gantt::gantt.read_only'));
        }

        [$previousStart, $previousEnd, $milestone] = $this->span($record)
            ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));

        // Snapped relative to where the bar was, the way the browser drags: the time
        // of day is kept and the delta is rounded to whole units.
        $previousMinutes = $this->minutes($previousStart, $previousEnd);
        $newStart = $this->unit->snapFrom($this->parse($start), $previousStart);
        $newEnd = $milestone
            ? $newStart
            : ($end === null ? $newStart->addMinutes($previousMinutes) : $this->unit->snapFrom($this->parse($end), $previousEnd));

        if ($newStart->equalTo($previousStart) && $newEnd->equalTo($previousEnd)) {
            return $this->presentBar($record);
        }

        $minutes = $this->minutes($newStart, $newEnd);
        $startChanged = ! $newStart->equalTo($previousStart);
        $endChanged = ! $newEnd->equalTo($previousEnd);

        // Classified by the edges, not by the duration alone: a bar that changed its
        // length and both its edges was moved as well as resized, and needs both rights.
        if ($minutes === $previousMinutes) {
            if (! $this->isMovable($record)) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_movable'));
            }
        } else {
            if ($startChanged && $endChanged && ! $this->isMovable($record)) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_movable'));
            }

            if (! $this->isResizable($record)) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_resizable'));
            }

            if ($minutes < $this->getMinMinutes()) {
                throw new MoveRejected(__('packstub-gantt::gantt.too_short', ['duration' => $this->humanMinutes($this->getMinMinutes())]));
            }

            if ($this->getMaxMinutes() !== null && $minutes > $this->getMaxMinutes()) {
                throw new MoveRejected(__('packstub-gantt::gantt.too_long', ['duration' => $this->humanMinutes($this->getMaxMinutes())]));
            }
        }

        if ($lock = $this->getLockedBefore()) {
            if (($startChanged && ($newStart < $lock || $previousStart < $lock)) || ($endChanged && $newEnd < $lock)) {
                throw new MoveRejected(__('packstub-gantt::gantt.locked', ['date' => $lock->translatedFormat(config('filament-gantt.date_format', 'M j, Y'))]));
            }
        }

        if ($this->canMove) {
            $verdict = ($this->canMove)($record, $newStart, $newEnd, $previousStart, $previousEnd);

            if ($verdict === false) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_allowed'));
            }

            if (is_string($verdict) && $verdict !== '') {
                throw new MoveRejected($verdict);
            }
        }

        try {
            if ($this->moveUsing) {
                ($this->moveUsing)($record, $newStart, $newEnd, $previousStart, $previousEnd);
            } else {
                $this->store($record, $newStart, $newEnd, $milestone, $previousStart);
            }
        } catch (MoveRejected $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MoveRejected($e->getMessage() ?: __('packstub-gantt::gantt.failed'), previous: $e);
        }

        BarMoved::dispatch($record, $previousStart, $previousEnd, $newStart, $newEnd, $this->key);

        if ($this->afterMove) {
            ($this->afterMove)($record, $newStart, $newEnd, $previousStart, $previousEnd);
        }

        unset($this->records[(string) $id]);

        return $this->presentBar($record->fresh() ?? $record) ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));
    }

    /**
     * Turn a chart range into the values a create form should show for the start
     * and end attributes (a date-only record's end day is the day before the
     * chart's end).
     *
     * @return array<string, string>
     */
    public function prefill(?string $start, ?string $end, ?string $group = null): array
    {
        $data = [];
        $dateOnly = $this->isDateOnly($this->newModel());

        try {
            if ($start !== null) {
                $from = $this->unit->snap($this->parse($start));
                $data[$this->startAttribute] = $dateOnly ? $from->toDateString() : $from->format('Y-m-d H:i:s');

                $to = $end === null ? $from->addMinutes($this->unit->minutes()) : $this->unit->snap($this->parse($end));
                $to = $to > $from ? $to : $from->addMinutes($this->unit->minutes());
                $data[$this->endAttribute] = $dateOnly ? $to->subDay()->toDateString() : $to->format('Y-m-d H:i:s');
            }
        } catch (MoveRejected) {
            // A garbled date from the browser: the form simply opens empty.
        }

        // The group only when the chart knows it (see canCreateIn()): a crafted key
        // never lands in a foreign key.
        if ($group !== null && $group !== '' && ($attribute = $this->getGroupForeignKey()) && $this->canCreateIn($group)) {
            $data[$attribute] = $group;
        }

        return $data;
    }

    /* ------------------------------------------------------------------ internals */

    public function baseQuery(): Builder
    {
        $query = $this->query instanceof Closure ? app()->call($this->query) : $this->query;

        if (! $query instanceof Builder) {
            throw new \LogicException('The Gantt needs a query(): an Eloquent builder or a closure returning one.');
        }

        return clone $query;
    }

    /** @param  array<string, mixed>  $filters */
    public function filteredQuery(array $filters = []): Builder
    {
        $query = $this->baseQuery();

        foreach ($this->filters as $filter) {
            $filter->apply($query, $filters[$filter->getName()] ?? null);
        }

        return $query;
    }

    /**
     * A record's span as the chart sees it: [start, end) and whether it is a milestone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool}|null
     */
    public function span(?Model $record = null): ?array
    {
        if ($record === null) {
            return $this->dataSpan();
        }

        $start = $this->toCarbon($record->getAttribute($this->startAttribute));

        if ($start === null) {
            return null;
        }

        $rawEnd = $record->getAttribute($this->endAttribute);
        $end = $this->toCarbon($rawEnd);
        $dateOnly = $this->isDateOnly($record);

        if ($this->milestone !== null) {
            $milestone = (bool) $this->read($this->milestone, $record);
        } else {
            $milestone = $end === null || (! $dateOnly && $end <= $start);
        }

        if ($dateOnly) {
            $start = $start->startOfDay();
            $end = $end?->startOfDay()->addDay();
        }

        if ($milestone || $end === null || $end <= $start) {
            return [$start, $start, true];
        }

        return [$start, $end, false];
    }

    /** Whether the record's dates are whole days (see dateOnly()). */
    public function isDateOnly(Model $record): bool
    {
        if ($this->dateOnly !== null) {
            return $this->dateOnly;
        }

        $cast = $record->getCasts()[$this->startAttribute] ?? null;

        if (is_string($cast)) {
            $type = strtolower(str($cast)->before(':')->toString());

            if (in_array($type, ['date', 'immutable_date'], true)) {
                return true;
            }

            if (in_array($type, ['datetime', 'immutable_datetime', 'timestamp', 'custom_datetime', 'immutable_custom_datetime'], true)) {
                return false;
            }
        }

        $raw = $record->getAttributes()[$this->startAttribute] ?? null;

        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1;
    }

    protected function store(Model $record, CarbonImmutable $start, CarbonImmutable $end, bool $milestone, CarbonImmutable $previousStart): void
    {
        $dateOnly = $this->isDateOnly($record);
        $format = fn (CarbonImmutable $moment) => $dateOnly ? $moment->toDateString() : $moment->format('Y-m-d H:i:s');

        $record->setAttribute($this->startAttribute, $format($start));

        if (! $milestone) {
            $record->setAttribute($this->endAttribute, $format($dateOnly ? $end->subDay() : $end));
        } elseif (($rawEnd = $this->toCarbon($record->getAttribute($this->endAttribute))) !== null) {
            // A milestone by the default rule had an end equal to its start: keep it so.
            // One flagged by milestone() keeps its real end, shifted along with the start.
            $record->setAttribute($this->endAttribute, $this->milestone === null
                ? $format($start)
                : $format($rawEnd->addMinutes($this->minutes($previousStart, $start))));
        }

        $record->save();
    }

    /** Wall-clock minutes from one moment to another (negative when going back). */
    protected function minutes(CarbonImmutable $from, CarbonImmutable $until): int
    {
        return (int) round($from->diffInMinutes($until, false));
    }

    /** Whether a group key is one the chart knows (see canCreateIn()). */
    protected function knowsGroup(string $group): bool
    {
        if ($this->groupAttribute === null && $this->groups === null) {
            return false;
        }

        foreach ($this->definedGroups() as $defined) {
            if ($defined->getKey() === $group) {
                return true;
            }
        }

        if ($this->groupAttribute === null) {
            return false;
        }

        $model = $this->newModel();

        if (! $model->isRelation($this->groupAttribute)) {
            return $this->baseQuery()->where($this->groupAttribute, $group)->exists();
        }

        $relation = $model->{$this->groupAttribute}();

        if (! $relation instanceof BelongsTo) {
            return false;
        }

        return $this->baseQuery()->where($relation->getQualifiedForeignKeyName(), $group)->exists()
            || $relation->getRelated()->newQuery()->whereKey($group)->exists();
    }

    /**
     * The earliest start and the latest end on the chart, as days; today when there is nothing.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function dataSpan(): array
    {
        $query = $this->baseQuery();
        $start = $query->qualifyColumn($this->startAttribute);
        $end = $query->qualifyColumn($this->endAttribute);

        $row = $query->reorder()->toBase()->selectRaw("min({$start}) as gantt_min, max({$end}) as gantt_max, max({$start}) as gantt_max_start")->first();

        $min = $this->toCarbon($row->gantt_min ?? null)?->startOfDay();
        $max = collect([$this->toCarbon($row->gantt_max ?? null), $this->toCarbon($row->gantt_max_start ?? null)])->filter()->max()?->startOfDay();

        $today = CarbonImmutable::today()->shiftTimezone('UTC');

        return [$min ?? $today, $max ?? $min ?? $today];
    }

    /** @return list<Group> */
    protected function definedGroups(): array
    {
        $groups = $this->groups instanceof Closure ? app()->call($this->groups) : $this->groups;

        return array_values(array_filter($groups ?? [], fn ($g) => $g instanceof Group));
    }

    protected function groupKey(Model $record): ?string
    {
        if ($this->groupAttribute === null) {
            return null;
        }

        if ($record->isRelation($this->groupAttribute)) {
            $relation = $record->{$this->groupAttribute}();
            $value = $relation instanceof BelongsTo
                ? $record->getAttribute($relation->getForeignKeyName())
                : $record->getRelationValue($this->groupAttribute)?->getKey();
        } else {
            $value = $record->getAttribute($this->groupAttribute);
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    protected function groupLabelFor(Model $record, string $key): string
    {
        if ($this->groupLabel instanceof Closure) {
            return (string) ($this->groupLabel)($record);
        }

        if (is_string($this->groupLabel)) {
            return (string) (data_get($record, $this->groupLabel) ?? $key);
        }

        if ($record->isRelation($this->groupAttribute)) {
            $related = $record->getRelationValue($this->groupAttribute);

            foreach (['name', 'title', 'label'] as $attribute) {
                if ($related !== null && filled($related->getAttribute($attribute))) {
                    return (string) $related->getAttribute($attribute);
                }
            }

            return $key;
        }

        $value = $record->getAttribute($this->groupAttribute);

        if ($value instanceof HasLabel) {
            return (string) $value->getLabel();
        }

        return str($key)->headline()->toString();
    }

    protected function read(string|Closure $source, Model $record): mixed
    {
        return $source instanceof Closure ? $source($record) : data_get($record, $source);
    }

    protected function evaluate(mixed $value, Model $record): mixed
    {
        return $value instanceof Closure ? $value($record) : $value;
    }

    /** A record's value (or anything Carbon reads) as a wall-clock moment tagged UTC; null when unreadable. */
    protected function toCarbon(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)->shiftTimezone('UTC')
                : CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /** What the browser sent ("Y-m-d H:i", or anything Carbon reads) as a wall-clock moment tagged UTC. */
    protected function parse(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(trim($value), 'UTC');
        } catch (\Throwable) {
            throw new MoveRejected(__('packstub-gantt::gantt.failed'));
        }
    }

    protected function humanMinutes(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            return trans_choice('packstub-gantt::gantt.days', intdiv($minutes, 1440), ['count' => intdiv($minutes, 1440)]);
        }

        if ($minutes % 60 === 0) {
            return trans_choice('packstub-gantt::gantt.hours', intdiv($minutes, 60), ['count' => intdiv($minutes, 60)]);
        }

        return trans_choice('packstub-gantt::gantt.minutes', $minutes, ['count' => $minutes]);
    }

    protected function newModel(): Model
    {
        return $this->baseQuery()->getModel();
    }
}
