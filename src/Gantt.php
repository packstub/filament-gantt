<?php

namespace Packstub\Gantt;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use LogicException;
use Packstub\Gantt\Enums\FiltersLayout;
use Packstub\Gantt\Enums\Scale;
use Packstub\Gantt\Enums\Unit;
use Packstub\Gantt\Events\BarMoved;
use Packstub\Gantt\Exceptions\MoveRejected;
use UnitEnum;

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
 * (canMove(), moveUsing(), afterMove()) and BarMoved receive copies of those
 * moments in the app's timezone (the same wall-clock time, tagged the way Eloquent
 * tags a record's dates), so `$start->isPast()` or a comparison with now() reads
 * naturally; only the chart's own maths stays on the UTC-tagged values.
 *
 * Closures: the ones about a record (label(), color(), bar(), movable(),
 * canMove(), moveUsing(), authorizeMoves()…) are called with positional arguments,
 * the record first, so they may name and type them as they like; the others
 * (query(), groups(), readOnly(), lockedBefore(), a Filter's options) are called
 * through the container, so they may ask for any service.
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

    protected string|Closure|null $dependencies = null;

    protected ?string $groupAttribute = null;

    protected string|Closure|null $groupLabel = null;

    /** @var list<Group>|Closure|null */
    protected array|Closure|null $groups = null;

    /** @var list<Group>|null groups() evaluated, once per instance */
    protected ?array $definedGroupsCache = null;

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

    protected bool|Closure $regroupable = true;

    protected ?Closure $canRegroup = null;

    protected bool|Closure $readOnly = false;

    protected ?bool $readOnlyCache = null;

    protected bool|Closure|null $authorizeMoves = null;

    /** @var array<string, bool> authorizeMoves() answers, by record key */
    protected array $authorizedMoves = [];

    /** @var list<Scale>|null */
    protected ?array $scales = null;

    protected ?Closure $actionsResolver = null;

    protected ?bool $sampledDateOnly = null;

    protected ?int $minMinutes = null;

    protected ?int $maxMinutes = null;

    protected CarbonInterface|Closure|null $lockedBefore = null;

    /** The evaluated lock for this request; false until asked for. */
    protected CarbonImmutable|null|false $lockedAt = false;

    protected ?Closure $canMove = null;

    protected ?Closure $moveUsing = null;

    protected ?Closure $afterMove = null;

    /** @var list<Filter> */
    protected array $filters = [];

    protected FiltersLayout|string|null $filtersLayout = null;

    /** @var list<string> */
    protected array $searchable = [];

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
     * Which bars a bar waits for (finish-to-start), drawn as arrows from each
     * predecessor's end to this bar's start: a JSON/array column of keys
     * (`depends_on`), a relation to the predecessors (`predecessors`, a BelongsToMany
     * or HasMany), or `fn (Model $record): iterable` returning keys or models. Drawn
     * only, never enforced: a successor that starts before its predecessor ends gets a
     * dashed arrow, and nothing is rescheduled or refused (add that in canMove() if
     * you want it). An arrow is drawn when both bars are on the chart.
     */
    public function dependencies(string|Closure|null $source): static
    {
        $this->dependencies = $source;

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
        $this->definedGroupsCache = null;

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

    /**
     * The zoom levels offered in the toolbar, in order. Default: day, week and month,
     * with hour first when the chart snaps to hours.
     *
     * @param  list<Scale|string>|null  $scales
     */
    public function scales(?array $scales): static
    {
        $this->scales = $scales === null ? null : array_values(array_unique(array_map(
            fn (Scale|string $scale) => $scale instanceof Scale ? $scale : Scale::fromName($scale),
            $scales,
        ), SORT_REGULAR));

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

    /**
     * Whether a bar may be dragged to another row (another group): a bool or
     * `fn (Model $record): bool`. Only on a chart grouped by a column or a BelongsTo,
     * and for a user authorizeMoves() allows; `groups()` and the rows on the chart
     * are the only targets.
     */
    public function regroupable(bool|Closure $condition = true): static
    {
        $this->regroupable = $condition;

        return $this;
    }

    /** Show only: nothing moves, nothing resizes, nothing is created. The widget's default. */
    public function readOnly(bool|Closure $condition = true): static
    {
        $this->readOnly = $condition;
        $this->readOnlyCache = null;

        return $this;
    }

    /**
     * Who may move and resize a record from the chart: a bool or
     * `fn (Model $record): bool`. Default: the model policy's `update` ability, when
     * the model has a policy with one; on a GanttResourcePage, the resource's
     * canEdit(). A record refused here is drawn locked, and its moves are refused on
     * the server whatever the browser sends. movable() and resizable() still apply
     * on top: they say what may change, this says who may change it.
     */
    public function authorizeMoves(bool|Closure|null $condition = true): static
    {
        $this->authorizeMoves = $condition;
        $this->authorizedMoves = [];

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
     * Nothing may be moved to before this moment, a bar that ended before it keeps its
     * dates and its row, and one that started before it keeps its start (its end may
     * still change, as long as it stays after it).
     * `lockedBefore(today())` keeps the past as it happened.
     */
    public function lockedBefore(CarbonInterface|Closure|null $moment): static
    {
        $this->lockedBefore = $moment;
        $this->lockedAt = false;

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
     * A last check before a bar lands in another group: `fn (Model $record, ?string $group,
     * ?string $previousGroup): bool|string` (the keys of the group column or the related
     * records, null for ungrouped). Return false to refuse, or a string to refuse with
     * that message. canMove() runs too, with the groups as its last two arguments.
     */
    public function canRegroup(?Closure $callback): static
    {
        $this->canRegroup = $callback;

        return $this;
    }

    /**
     * What a move does: `fn (Model $record, CarbonImmutable $start, CarbonImmutable $end,
     * CarbonImmutable $previousStart, CarbonImmutable $previousEnd, ?string $group,
     * ?string $previousGroup): void` (the groups only when the chart is grouped). Throw
     * MoveRejected to refuse it with a message the user sees; any other exception
     * is reported and the user sees "The change did not go through." Default: write
     * the start and end attributes and save. The dates are the chart's half-open
     * range; a date-only record's end day is `$end->subDay()`.
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
     * Where the filters are shown: behind a funnel button in the toolbar (the
     * default, as a table's), inline in the toolbar, or on a row above the chart.
     * Default: the config's `filters_layout`.
     */
    public function filtersLayout(FiltersLayout|string|null $layout): static
    {
        $this->filtersLayout = $layout;

        return $this;
    }

    /**
     * Attributes the toolbar's search box looks in (`like %term%`, case-insensitive
     * on PostgreSQL), a dotted path for a relation's attribute (`project.name`).
     * Without any, there is no search box.
     *
     * @param  list<string>  $attributes
     */
    public function searchable(array $attributes): static
    {
        $this->searchable = array_values(array_filter($attributes, 'is_string'));

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
     * group the chart knows: one listed in groups() or one a bar of the chart is
     * in, the only rows a user can click; a related record that exists but has no
     * row on the chart is refused. The group is written to the new record only
     * when this says yes; a form field for the foreign key
     * (`Select::make('project_id')->relationship(...)`) stays the place to scope
     * it to the tenant.
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
     * @param  list<string>  $events  names of letters, digits, `_`, `.`, `:` and `-` (they end up in the view's `x-on:` attributes)
     *
     * @throws InvalidArgumentException for a name of another shape
     */
    public function refreshOn(array $events): static
    {
        foreach ($events as $event) {
            if (! is_string($event) || ! preg_match('/^[\w.:-]+$/', $event)) {
                throw new InvalidArgumentException(sprintf('A refreshOn() event name holds letters, digits, "_", ".", ":" and "-" only, [%s] given.', is_string($event) ? $event : get_debug_type($event)));
            }
        }

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
                ?: throw new InvalidArgumentException("Cannot read the poll interval [{$interval}]: use '10s', '1m' or milliseconds.");
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

    /**
     * The attribute a new record's group is written to: the column, or a BelongsTo's foreign key.
     *
     * @internal
     */
    public function getGroupForeignKey(): ?string
    {
        if ($this->groupAttribute === null) {
            return null;
        }

        if (! $this->newModel()->isRelation($this->groupAttribute)) {
            return $this->groupAttribute;
        }

        $relation = $this->groupRelation();

        return $relation instanceof BelongsTo ? $relation->getForeignKeyName() : null;
    }

    /**
     * The groupBy() relation, or null for a column.
     *
     * @throws LogicException for a MorphTo: its id alone names no group
     */
    protected function groupRelation(): ?Relation
    {
        if ($this->groupAttribute === null) {
            return null;
        }

        $model = $this->newModel();

        if (! $model->isRelation($this->groupAttribute)) {
            return null;
        }

        $relation = $model->{$this->groupAttribute}();

        if ($relation instanceof MorphTo) {
            throw new LogicException("The Gantt cannot group by the MorphTo relation [{$this->groupAttribute}]: two related records of different types may share an id. Group by a column, or by a BelongsTo relation.");
        }

        return $relation;
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
        $scale = $this->scale ?? Scale::fromName(config('filament-gantt.default_scale'));
        $scales = $this->getScales();

        return in_array($scale, $scales, true) ? $scale : ($scales[0] ?? Scale::Week);
    }

    /** @return list<Scale> */
    public function getScales(): array
    {
        if ($this->scales !== null && $this->scales !== []) {
            return $this->scales;
        }

        return $this->unit === Unit::Hour
            ? [Scale::Hour, Scale::Day, Scale::Week, Scale::Month, Scale::Year]
            : [Scale::Day, Scale::Week, Scale::Month, Scale::Year];
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function getFirstDayOfWeek(): int
    {
        return $this->firstDayOfWeek ?? max(1, min(7, (int) config('filament-gantt.first_day_of_week', 1)));
    }

    /** Evaluated once per instance (a request): every bar asks. */
    public function isReadOnly(): bool
    {
        return $this->readOnlyCache ??= (bool) ($this->readOnly instanceof Closure ? app()->call($this->readOnly) : $this->readOnly);
    }

    /** Whether the user may move and resize this record (see authorizeMoves()). */
    public function canMoveRecord(Model $record): bool
    {
        $key = (string) $record->getKey();

        if (array_key_exists($key, $this->authorizedMoves)) {
            return $this->authorizedMoves[$key];
        }

        if ($this->authorizeMoves === null) {
            $policy = Gate::getPolicyFor($record);

            if ($policy === null) {
                $allowed = true;
            } elseif (method_exists($policy, 'update')) {
                $allowed = Gate::allows('update', $record);
            } elseif (method_exists($policy, 'before')) {
                // No update() rule, but a before() that may still say no (a suspended
                // user): the Gate alone would refuse everyone for the missing method.
                $user = (Filament::getCurrentPanel() ? Filament::auth() : auth())->user();
                $allowed = $user === null || ($policy->before($user, 'update') ?? true) !== false;
            } else {
                $allowed = true;
            }
        } else {
            $allowed = (bool) $this->evaluate($this->authorizeMoves, $record);
        }

        return $record->exists ? $this->authorizedMoves[$key] = $allowed : $allowed;
    }

    public function isMovable(Model $record): bool
    {
        return ! $this->isReadOnly() && $this->canMoveRecord($record) && (bool) $this->evaluate($this->movable, $record);
    }

    public function isResizable(Model $record): bool
    {
        return ! $this->isReadOnly() && $this->canMoveRecord($record) && (bool) $this->evaluate($this->resizable, $record);
    }

    /** Whether this record may be dragged to another row: the chart groups by something writable, and the rules allow it. */
    public function isRegroupable(Model $record): bool
    {
        return $this->getGroupForeignKey() !== null && ! $this->isReadOnly() && $this->canMoveRecord($record) && (bool) $this->evaluate($this->regroupable, $record);
    }

    public function getMinMinutes(): int
    {
        return $this->minMinutes ?? $this->unit->minutes();
    }

    /** @throws LogicException when the longest a bar may be is shorter than the shortest */
    public function getMaxMinutes(): ?int
    {
        if ($this->maxMinutes !== null && $this->maxMinutes < $this->getMinMinutes()) {
            throw new LogicException("The Gantt's maxDuration() ({$this->maxMinutes} minutes) is shorter than its minDuration() or one snap unit ({$this->getMinMinutes()} minutes): no resize could ever be saved.");
        }

        return $this->maxMinutes;
    }

    /**
     * A bar wholly before the lock: it ended by then (a half-open range ending on
     * the lock is over) and started before it. A milestone on the lock is not past.
     */
    protected function endedBefore(CarbonInterface $start, CarbonInterface $end, CarbonInterface $lock): bool
    {
        return $end <= $lock && $start < $lock;
    }

    /** The lock, evaluated once per request (its closure is often `now()`). */
    public function getLockedBefore(): ?CarbonImmutable
    {
        if ($this->lockedBefore === null) {
            return null;
        }

        if ($this->lockedAt === false) {
            $moment = $this->lockedBefore instanceof Closure ? app()->call($this->lockedBefore) : $this->lockedBefore;
            $this->lockedAt = $moment === null ? null : CarbonImmutable::instance(Date::parse($moment))->shiftTimezone('UTC');
        }

        return $this->lockedAt;
    }

    /** @return list<Filter> */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /** The definition's layout, else the config's `filters_layout` (a value that is no layout throws). */
    public function getFiltersLayout(): FiltersLayout
    {
        return FiltersLayout::fromConfig($this->filtersLayout ?? config('filament-gantt.filters_layout'));
    }

    /** @return list<string> */
    public function getSearchable(): array
    {
        return $this->searchable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable !== [];
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

    /** The PHP date format for whole days: the config's, or the locale's. */
    public static function dateFormat(): string
    {
        return (string) (config('filament-gantt.date_format') ?: __('packstub-gantt::gantt.date_format'));
    }

    /** The PHP date format for moments with a time: the config's, or the locale's. */
    public static function dateTimeFormat(): string
    {
        return (string) (config('filament-gantt.datetime_format') ?: __('packstub-gantt::gantt.datetime_format'));
    }

    /**
     * How a bar finds the actions the user may run on its record: set by the
     * component (Filament's visibility and authorization, record by record).
     *
     * @internal
     *
     * @param  (Closure(Model): list<string>)|null  $resolver
     */
    public function resolveActionsUsing(?Closure $resolver): static
    {
        $this->actionsResolver = $resolver;

        return $this;
    }

    /**
     * Whether a bar action is offered on this record by Bar::actions() (every one,
     * unless the bar() closure narrows them). Checked again when the action mounts.
     *
     * @internal
     */
    public function offersAction(Model $record, string $name): bool
    {
        $actions = $this->bar && ($custom = ($this->bar)($record)) instanceof Bar ? $custom->getActions() : null;

        return $actions === null || in_array($name, $actions, true);
    }

    /**
     * The record behind a bar, if it is still on this chart for this user.
     *
     * @internal
     */
    public function findRecord(string|int|null $id): ?Model
    {
        if (blank($id)) {
            return null;
        }

        // An integer key never matches 'abc': asked anyway, PostgreSQL throws a QueryException
        // (a 500 from an action's hidden()), so a bar id of another shape is simply not found.
        if (! is_int($id) && $this->newModel()->getKeyType() === 'int' && ! ctype_digit((string) $id)) {
            return null;
        }

        return array_key_exists($key = (string) $id, $this->records)
            ? $this->records[$key]
            : $this->records[$key] = $this->baseQuery()->whereKey($key)->first();
    }

    /**
     * The days shown, as whole days [start, end] inclusive. The browser may ask for
     * its own window; the definition's startDate()/endDate() come next; otherwise a
     * padded span around the bars. At most `max_window_days` long; any other window
     * is at least `min_window_days`, unless `$exact` (a range the user typed in full,
     * or the span Fit loads: the browser says so) asks for it as it is, down to one day.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function getWindow(?string $from = null, ?string $until = null, bool $exact = false): array
    {
        $typed = $exact && Filter::date($from) && Filter::date($until);
        $start = Filter::date($from) ? CarbonImmutable::parse($from, 'UTC')->startOfDay() : $this->windowStart;
        $end = Filter::date($until) ? CarbonImmutable::parse($until, 'UTC')->startOfDay() : $this->windowEnd;

        $minDays = max(7, (int) config('filament-gantt.min_window_days', 28));
        $maxDays = max($minDays, (int) config('filament-gantt.max_window_days', 730));
        $before = (int) config('filament-gantt.window_padding_days.before', 7);
        $after = (int) config('filament-gantt.window_padding_days.after', 14);

        if ($start === null && $end === null) {
            [$min, $max] = $this->dataSpan();
            $start = $min->subDays($before);
            $end = $max->addDays($after);

            // A long history (shifts, bookings over years) opens around today, not on
            // its oldest years: the user moves from there.
            if ((int) $start->diffInDays($end) + 1 > $maxDays) {
                $today = CarbonImmutable::today()->shiftTimezone('UTC');
                $start = $today->subDays($before);
                $end = $today->addDays($after);
            }
        } elseif ($start === null || $end === null) {
            [$min, $max] = $this->dataSpan();
            $start ??= $min->subDays($before);
            $end ??= $max->addDays($after);
        }

        // "Until" before "from": the user swapped them.
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $days = (int) $start->diffInDays($end) + 1;

        if ($days < $minDays && ! $typed) {
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
    public function getState(array $filters = [], ?string $from = null, ?string $until = null, bool $exact = false, string $search = ''): array
    {
        $window = $this->getWindow($from, $until, $exact);
        $query = $this->windowQuery($filters, $window['start'], $window['end'], $search);
        $limit = max(1, (int) config('filament-gantt.max_bars', 2000));

        $records = (clone $query)->limit($limit + 1)->get();
        $total = $records->count() > $limit ? (clone $query)->toBase()->getCountForPagination() : $records->count();
        $bars = $this->presentBars($records->take($limit));

        return [
            'window' => ['start' => $window['start']->toDateString(), 'end' => $window['end']->toDateString()],
            'groups' => $this->getGroups($bars),
            'bars' => $bars,
            // More than max_bars in the window: the first ones are drawn and the toolbar says so.
            'total' => $total,
        ];
    }

    /**
     * The bars overlapping a window (all of them without one), in the chart's order.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getBars(array $filters = [], ?CarbonInterface $from = null, ?CarbonInterface $until = null, string $search = ''): array
    {
        return $this->presentBars($this->windowQuery($filters, $from, $until, $search)->get());
    }

    /**
     * @param  iterable<Model>  $records
     * @return list<array<string, mixed>>
     */
    protected function presentBars(iterable $records): array
    {
        return collect($records)
            ->map(fn (Model $record) => $this->presentBar($record))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The chart's records overlapping a window (all of them without one), filtered and in order.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function windowQuery(array $filters = [], ?CarbonInterface $from = null, ?CarbonInterface $until = null, string $search = ''): Builder
    {
        $query = $this->filteredQuery($filters, $search);
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

        // The group's label reads the relation on every bar: loaded once, not per bar
        // (and never lazily, which strict mode forbids). The predecessors relation too.
        if ($this->groupRelation() !== null && ! $this->groupLabel instanceof Closure && ! array_key_exists($this->groupAttribute, $query->getEagerLoads())) {
            $query->with($this->groupAttribute);
        }

        if (is_string($this->dependencies) && $query->getModel()->isRelation($this->dependencies) && ! array_key_exists($this->dependencies, $query->getEagerLoads())) {
            $query->with($this->dependencies);
        }

        [$column, $direction] = $this->sort ?? [$this->startAttribute, 'asc'];

        return $query
            ->orderBy($query->qualifyColumn($column), $direction)
            ->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * The groups in order: the definition's, plus any met on a bar that was not
     * listed, or the bars' in order of first appearance.
     *
     * @internal
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

    /**
     * @internal
     *
     * @return array<string, mixed>|null null when the record has no start
     */
    public function presentBar(Model $record): ?array
    {
        $span = $this->span($record);

        if ($span === null) {
            return null;
        }

        [$start, $end, $milestone] = $span;

        $bar = Bar::make()
            ->label($this->label === null ? (string) $record->getKey() : static::text($this->read($this->label, $record)))
            ->color($this->color === null ? null : $this->read($this->color, $record))
            ->progress($this->progress === null ? null : $this->read($this->progress, $record));

        $shape = $bar->toArray();

        if ($this->bar && ($custom = ($this->bar)($record)) instanceof Bar) {
            $shape = [...$shape, ...$custom->toArray()];
        }

        // The actions this user may run on this record (Filament's visibility and
        // authorization), within those Bar::actions() offers.
        if ($this->actionsResolver) {
            $allowed = ($this->actionsResolver)($record);
            $shape['actions'] = array_values(isset($shape['actions']) ? array_intersect($shape['actions'], $allowed) : $allowed);
        }

        $group = $this->groupKey($record);

        if ($group !== null && ! isset($this->groupLabels[$group])) {
            $this->groupLabels[$group] = $this->groupLabelFor($record, $group);
        }

        $bar = [
            'id' => (string) $record->getKey(),
            'group' => $group ?? '',
            'start' => $start->format('Y-m-d H:i'),
            'end' => $end->format('Y-m-d H:i'),
            'allDay' => $this->isDateOnly($record),
            'milestone' => $milestone,
            'movable' => $this->isMovable($record),
            'resizable' => ! $milestone && $this->isResizable($record),
            'regroupable' => $this->isRegroupable($record),
            ...$shape,
        ];

        // What the lock refuses (move()) is drawn locked, not tried: a bar that ended
        // before the lock keeps its dates and its row, one that started before it keeps
        // its start (its end may still move, so only its start handle goes).
        if ($lock = $this->getLockedBefore()) {
            if ($this->endedBefore($start, $end, $lock)) {
                $bar['movable'] = $bar['resizable'] = $bar['regroupable'] = false;
            } elseif ($start < $lock) {
                $bar['movable'] = false;
                $bar['startFixed'] = true;
            }
        }

        if ($this->dependencies !== null) {
            $bar['deps'] = $this->dependenciesOf($record);
        }

        return $bar;
    }

    /**
     * The keys of the bars this record waits for, as strings (see dependencies()).
     *
     * @return list<string>
     */
    protected function dependenciesOf(Model $record): array
    {
        $source = $this->dependencies;

        if ($source instanceof Closure) {
            $value = $source($record);
        } elseif ($record->isRelation($source)) {
            $value = $record->getRelationValue($source);
        } else {
            $value = $record->getAttribute($source);

            if (is_string($value) && str_starts_with(trim($value), '[')) {
                $value = json_decode($value, true);
            }
        }

        return collect($value instanceof Model ? [$value] : ($value ?? []))
            ->map(fn ($item) => $item instanceof Model ? $item->getKey() : ($item instanceof BackedEnum ? $item->value : $item))
            ->filter(fn ($key) => (is_string($key) && $key !== '') || is_int($key))
            ->map(fn ($key) => (string) $key)
            ->unique()
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ writing */

    /**
     * Move or resize a bar, enforcing every rule on the server whatever the browser
     * claimed. Dates are the chart's half-open range as `Y-m-d H:i` (seconds allowed,
     * a date alone too); without an end the duration is kept; with a group (a row's
     * key, '' for the ungrouped row) the bar lands in that group too. Returns the bar
     * as it looks afterwards. A refused move throws MoveRejected; an exception thrown
     * while saving (the database's, moveUsing()'s) propagates as it is.
     *
     * @return array<string, mixed>
     *
     * @throws MoveRejected
     */
    public function move(string $id, string $start, ?string $end = null, ?string $group = null): array
    {
        $record = $this->findRecord($id)
            ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));

        if ($this->isReadOnly()) {
            throw new MoveRejected(__('packstub-gantt::gantt.read_only'));
        }

        if (! $this->canMoveRecord($record)) {
            throw new MoveRejected(__('packstub-gantt::gantt.not_allowed'));
        }

        [$previousStart, $previousEnd, $milestone] = $this->span($record)
            ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));

        // Snapped relative to where the bar was, the way the browser drags: the time
        // of day is kept and the delta is rounded to whole units (whole days for a
        // date-only record, whatever snapTo() says: it has no hours to move).
        $unit = $this->unitFor($record);
        $previousMinutes = $this->minutes($previousStart, $previousEnd);
        $newStart = $unit->snapFrom($this->parse($start), $previousStart);
        $newEnd = $milestone
            ? $newStart
            : ($end === null ? $newStart->addMinutes($previousMinutes) : $unit->snapFrom($this->parse($end), $previousEnd));

        // The row the bar lands in: the one it is in, unless the browser named another
        // ('' is the ungrouped row). Only a row that is on the chart, never a related
        // record that merely exists (see knowsGroup()).
        $previousGroup = $this->groupKey($record);
        $newGroup = $group === null ? $previousGroup : ($group === '' ? null : $group);
        $regroup = $newGroup !== $previousGroup;

        if ($regroup) {
            if (! $this->isRegroupable($record)) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_regroupable'));
            }

            if (! $this->knowsRow($group)) {
                throw new MoveRejected(__('packstub-gantt::gantt.unknown_group'));
            }
        }

        if ($newStart->equalTo($previousStart) && $newEnd->equalTo($previousEnd) && ! $regroup) {
            return $this->presentBar($record);
        }

        $minutes = $this->minutes($newStart, $newEnd);
        $startChanged = ! $newStart->equalTo($previousStart);
        $endChanged = ! $newEnd->equalTo($previousEnd);

        // Classified by the edges, not by the duration alone: a bar that changed its
        // length and both its edges was moved as well as resized, and needs both rights.
        // A change of row alone needs neither: regroupable() decided above.
        if ($minutes === $previousMinutes) {
            if (($startChanged || $endChanged) && ! $this->isMovable($record)) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_movable'));
            }
        } else {
            if ($startChanged && $endChanged && ! $this->isMovable($record)) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_movable'));
            }

            if (! $this->isResizable($record)) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_resizable'));
            }

            [$min, $max] = $this->limitsFor($unit);

            if ($minutes < $min) {
                throw new MoveRejected(__('packstub-gantt::gantt.too_short', ['duration' => $this->humanMinutes($min)]));
            }

            if ($max !== null && $minutes > $max) {
                throw new MoveRejected(__('packstub-gantt::gantt.too_long', ['duration' => $this->humanMinutes($max)]));
            }
        }

        if ($lock = $this->getLockedBefore()) {
            // A bar wholly before the lock keeps its dates and its row: the past stays as
            // it happened. One that started before it keeps its start.
            $past = $this->endedBefore($previousStart, $previousEnd, $lock);

            if (($past && ($startChanged || $endChanged || $regroup)) || ($startChanged && ($newStart < $lock || $previousStart < $lock)) || ($endChanged && $newEnd < $lock)) {
                // A lock at midnight reads as a date; one during the day (now()) says the time too.
                $format = $lock->isStartOfDay() ? static::dateFormat() : static::dateTimeFormat();

                throw new MoveRejected(__('packstub-gantt::gantt.locked', ['date' => $lock->translatedFormat($format)]));
            }
        }

        // The closures and the event see the same wall-clock moments in the app's
        // timezone, the tag Eloquent gives a record's dates, so now() and isPast()
        // read naturally; the maths above and the save below keep the UTC-tagged ones.
        [$start, $end, $from, $until] = array_map($this->inAppTimezone(...), [$newStart, $newEnd, $previousStart, $previousEnd]);

        if ($regroup && $this->canRegroup) {
            $this->verdict(($this->canRegroup)($record, $newGroup, $previousGroup));
        }

        if ($this->canMove) {
            $this->verdict(($this->canMove)($record, $start, $end, $from, $until, $newGroup, $previousGroup));
        }

        // One transaction from the save to the bar the browser gets back: when
        // afterMove(), a listener or the bar's presentation fails, the save is undone
        // too, so the browser putting the bar back matches the database. A MoveRejected
        // thrown here refuses with its message; anything else (a database error, a bug
        // in a closure) propagates for the caller to report: its message is not for
        // the user (see InteractsWithGantt::ganttMove()).
        return $record->getConnection()->transaction(function () use ($id, $record, $newStart, $newEnd, $milestone, $previousStart, $start, $end, $from, $until, $regroup, $newGroup, $previousGroup) {
            if ($this->moveUsing) {
                ($this->moveUsing)($record, $start, $end, $from, $until, $newGroup, $previousGroup);
            } else {
                $this->store($record, $newStart, $newEnd, $milestone, $previousStart, $regroup ? [$newGroup] : null);
            }

            BarMoved::dispatch($record, $from, $until, $start, $end, $this->key, $previousGroup, $newGroup);

            if ($this->afterMove) {
                ($this->afterMove)($record, $start, $end, $from, $until, $newGroup, $previousGroup);
            }

            unset($this->records[(string) $id]);

            // Read back through the chart's query, so what it adds (withCount, joins,
            // eager loads) is still on the bar; a record the move took out of the query
            // is presented as it is.
            $fresh = $this->baseQuery()->whereKey($record->getKey())->first() ?? $record->fresh() ?? $record;

            return $this->presentBar($fresh) ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));
        });
    }

    /**
     * Turn a chart range into the values a create form should show for the start
     * and end attributes (a date-only record's end day is the day before the
     * chart's end).
     *
     * @internal
     *
     * @return array<string, string>
     */
    public function prefill(?string $start, ?string $end, ?string $group = null): array
    {
        $data = [];
        $model = $this->newModel();
        $dateOnly = $this->isDateOnly($model);
        // Whole days for a date-only record, whatever snapTo() says (as move() does):
        // an hour's click on such a chart would otherwise end the day before it starts.
        $unit = $this->unitFor($model);

        try {
            if ($start !== null) {
                $from = $unit->snap($this->parse($start));
                $to = $end === null ? $from->addMinutes($unit->minutes()) : $unit->snap($this->parse($end));
                $to = $to > $from ? $to : $from->addMinutes($unit->minutes());

                $data[$this->startAttribute] = $dateOnly ? $from->toDateString() : $from->format('Y-m-d H:i:s');
                $data[$this->endAttribute] = $dateOnly ? $to->subDay()->toDateString() : $to->format('Y-m-d H:i:s');
            }
        } catch (MoveRejected) {
            // A garbled date from the browser: the form simply opens empty, both dates.
        }

        // The group only when the chart knows it (see canCreateIn()): a crafted key
        // never lands in a foreign key.
        if ($group !== null && $group !== '' && ($attribute = $this->getGroupForeignKey()) && $this->canCreateIn($group)) {
            $data[$attribute] = $group;
        }

        return $data;
    }

    /* ------------------------------------------------------------------ internals */

    /** @internal */
    public function baseQuery(): Builder
    {
        $query = $this->query instanceof Closure ? app()->call($this->query) : $this->query;

        if (! $query instanceof Builder) {
            throw new LogicException('The Gantt needs a query(): an Eloquent builder or a closure returning one.');
        }

        return clone $query;
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(array $filters = [], string $search = ''): Builder
    {
        $query = $this->baseQuery();

        foreach ($this->filters as $filter) {
            $filter->apply($query, $filters[$filter->getName()] ?? null);
        }

        if (($search = trim(mb_substr($search, 0, 200))) !== '') {
            $this->applySearch($query, $search);
        }

        return $query;
    }

    /**
     * `like %term%` over every searchable() attribute, a dotted path through
     * `whereHas`; nothing without any. `!` escapes the wildcards, with an explicit
     * ESCAPE clause: SQLite has no escape character by default, and a backslash
     * there is a parse error on MySQL, while `!` works on all three, so "100%"
     * finds "100% done" everywhere. Case-insensitive on PostgreSQL (`ilike`), as
     * MySQL's and SQLite's `like` already are.
     */
    protected function applySearch(Builder $query, string $term): void
    {
        if ($this->searchable === []) {
            return;
        }

        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $match = fn (Builder $q, string $column, string $boolean) => $q->whereRaw($q->getGrammar()->wrap($q->qualifyColumn($column))." {$operator} ? escape '!'", [$like], $boolean);

        $query->where(function (Builder $q) use ($match): void {
            foreach ($this->searchable as $path) {
                if (str_contains($path, '.')) {
                    // The relation's own constraint is an AND inside the EXISTS; the OR is between the paths.
                    $q->orWhereHas(str($path)->beforeLast('.')->toString(), fn (Builder $r) => $match($r, str($path)->afterLast('.')->toString(), 'and'));
                } else {
                    $match($q, $path, 'or');
                }
            }
        });
    }

    /**
     * A record's span as the chart sees it: [start, end) and whether it is a
     * milestone; null when it has no start.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool}|null
     */
    public function span(Model $record): ?array
    {
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

    /**
     * Whether the record's dates are whole days (see dateOnly()).
     *
     * @internal
     */
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

        // A new record (the create form's prefill) has no value to look at: a stored
        // one decides, read once.
        if ($raw === null && ! $record->exists) {
            return $this->sampledDateOnly ??= $this->sampleIsDateOnly();
        }

        return static::isDateString($raw);
    }

    /** The snap unit for a record: whole days for a date-only one, whatever snapTo() says. */
    protected function unitFor(Model $record): Unit
    {
        return $this->isDateOnly($record) ? Unit::Day : $this->unit;
    }

    /**
     * The shortest and the longest a bar may be resized to at a unit: minDuration()
     * and maxDuration(), rounded to whole units the way the browser rounds them.
     *
     * @return array{0: int, 1: int|null}
     */
    protected function limitsFor(Unit $unit): array
    {
        $step = $unit->minutes();
        $min = (int) ceil(max($step, $this->getMinMinutes()) / $step) * $step;
        $max = $this->getMaxMinutes();

        return [$min, $max === null ? null : max($min, intdiv($max, $step) * $step)];
    }

    protected function sampleIsDateOnly(): bool
    {
        $query = $this->baseQuery();
        $column = $query->qualifyColumn($this->startAttribute);

        // Any one row will do: the query's own columns and order go (an ORDER BY on an
        // alias the dropped select defined, a withCount() say, would otherwise fail).
        $raw = $query->toBase()->cloneWithout(['columns', 'orders'])->cloneWithoutBindings(['select', 'order'])->whereNotNull($column)->value($column);

        return static::isDateString($raw);
    }

    protected static function isDateString(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * Write the dates (and, when a one-element list is given, the group) and save.
     *
     * @param  array{0: ?string}|null  $group
     */
    protected function store(Model $record, CarbonImmutable $start, CarbonImmutable $end, bool $milestone, CarbonImmutable $previousStart, ?array $group = null): void
    {
        $dateOnly = $this->isDateOnly($record);

        if ($group !== null) {
            $record->setAttribute($this->getGroupForeignKey(), $group[0]);
            // The loaded relation would still say the old group (the label, the key).
            if ($record->isRelation($this->groupAttribute)) {
                $record->unsetRelation($this->groupAttribute);
            }
        }
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

    /** A canMove()/canRegroup() answer: false refuses, a string refuses with that message. */
    protected function verdict(mixed $verdict): void
    {
        if ($verdict === false) {
            throw new MoveRejected(__('packstub-gantt::gantt.not_allowed'));
        }

        if (is_string($verdict) && $verdict !== '') {
            throw new MoveRejected($verdict);
        }
    }

    /** Whether a row key names a row on the chart: a group it knows, or '' when there is an ungrouped row. */
    protected function knowsRow(string $group): bool
    {
        if ($group !== '') {
            return $this->knowsGroup($group);
        }

        $query = $this->baseQuery();

        return $query->whereNull($query->qualifyColumn($this->getGroupForeignKey()))->exists();
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
            $query = $this->baseQuery();

            return $query->where($query->qualifyColumn($this->groupAttribute), $group)->exists();
        }

        $relation = $this->groupRelation();

        if (! $relation instanceof BelongsTo) {
            return false;
        }

        // A related record that exists but has no bar on the chart is no row the user
        // could click: accepting it would let a crafted request park a record under it.
        return $this->baseQuery()->where($relation->getQualifiedForeignKeyName(), $group)->exists();
    }

    /**
     * The earliest start and the latest end on the chart, as days; today when there is nothing.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function dataSpan(): array
    {
        $today = CarbonImmutable::today()->shiftTimezone('UTC');
        [$min, $max] = $this->spanOf([]) ?? [$today, $today];

        return [$min, $max];
    }

    /**
     * The first day and the last day of the bars the filters leave, as `Y-m-d`, or
     * null when there are none: what "Fit" loads.
     *
     * @param  array<string, mixed>  $filters
     * @return array{start: string, end: string}|null
     */
    public function getSpan(array $filters = [], string $search = ''): ?array
    {
        $span = $this->spanOf($filters, $search);

        return $span === null ? null : ['start' => $span[0]->toDateString(), 'end' => $span[1]->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    protected function spanOf(array $filters, string $search = ''): ?array
    {
        $query = $this->filteredQuery($filters, $search);
        $start = $query->qualifyColumn($this->startAttribute);
        $end = $query->qualifyColumn($this->endAttribute);

        // The two columns alone, from a subquery: whatever the chart's query selects
        // (withCount, addSelect, a join) never sits next to an aggregate without a
        // GROUP BY (MySQL's ONLY_FULL_GROUP_BY, PostgreSQL), and the grammar wraps the
        // names (a table prefix, quoting).
        $inner = $query->toBase();
        $inner = ($inner->limit === null && $inner->offset === null ? $inner->cloneWithout(['columns', 'orders'])->cloneWithoutBindings(['select', 'order']) : $inner->cloneWithout(['columns'])->cloneWithoutBindings(['select']))
            ->select(["{$start} as gantt_start", "{$end} as gantt_end"]);

        $row = $inner->newQuery()
            ->fromSub($inner, 'gantt_span')
            ->selectRaw('min(gantt_start) as gantt_min, max(gantt_end) as gantt_max, max(gantt_start) as gantt_max_start')
            ->first();

        $min = $this->toCarbon($row->gantt_min ?? null)?->startOfDay();
        $max = collect([$this->toCarbon($row->gantt_max ?? null), $this->toCarbon($row->gantt_max_start ?? null)])->filter()->max()?->startOfDay();

        return $min === null ? null : [$min, $max ?? $min];
    }

    /**
     * The groups() list, evaluated once per instance: a create from a click asks
     * for it several times in one request (hidden, prefill…), the closure's query
     * runs once.
     *
     * @return list<Group>
     */
    protected function definedGroups(): array
    {
        if ($this->definedGroupsCache !== null) {
            return $this->definedGroupsCache;
        }

        $groups = $this->groups instanceof Closure ? app()->call($this->groups) : $this->groups;

        return $this->definedGroupsCache = array_values(array_filter($groups ?? [], fn ($g) => $g instanceof Group));
    }

    protected function groupKey(Model $record): ?string
    {
        if ($this->groupAttribute === null) {
            return null;
        }

        if ($record->isRelation($this->groupAttribute)) {
            $relation = $this->groupRelation();
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
            return static::text(($this->groupLabel)($record));
        }

        if (is_string($this->groupLabel)) {
            return static::text(data_get($record, $this->groupLabel) ?? $key);
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

    /** A value as text: an enum by its label (HasLabel) or its value, anything else cast. */
    protected static function text(mixed $value): string
    {
        return match (true) {
            $value instanceof HasLabel => (string) $value->getLabel(),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            default => (string) $value,
        };
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

    /** The same wall-clock moment tagged with the app's timezone: what the closures and the event receive. */
    protected function inAppTimezone(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->shiftTimezone(config('app.timezone') ?: 'UTC');
    }

    /**
     * What the browser sent as a wall-clock moment tagged UTC: "Y-m-d", "Y-m-d H:i"
     * or "Y-m-d H:i:s" (a T between date and time is fine), a real calendar date
     * in the years 1000–9999, the only shape the chart ever sends. Anything else
     * ("0000-00-00", "2026-02-30", a phrase Carbon would guess at) is refused
     * before it can reach a record.
     */
    protected function parse(string $value): CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m) !== 1
            || (int) $m[1] < 1000
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) ($m[4] ?? 0) > 23
            || (int) ($m[5] ?? 0) > 59
            || (int) ($m[6] ?? 0) > 59) {
            throw new MoveRejected(__('packstub-gantt::gantt.failed'));
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0), 'UTC');
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
