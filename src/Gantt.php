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
use Illuminate\Database\Eloquent\Collection;
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

    protected ?string $lineAttribute = null;

    /** getLineForeignKey(), resolved once per instance: every bar asks. */
    protected ?string $lineForeignKeyCache = null;

    protected bool|Closure $preventLineOverlap = false;

    protected ?bool $preventLineOverlapCache = null;

    protected bool $movePiecesAlone = false;

    protected ?string $rowAttribute = null;

    protected string|Closure|null $rowLabel = null;

    /** @var list<Row>|Closure|null */
    protected array|Closure|null $rows = null;

    /** @var list<Row>|null rows() evaluated, once per instance */
    protected ?array $definedRowsCache = null;

    /** getRowForeignKey(), resolved once per instance: every bar asks. */
    protected ?string $rowForeignKeyCache = null;

    protected bool|Closure $preventRowOverlap = false;

    protected ?bool $preventRowOverlapCache = null;

    protected ?Closure $creatableInRow = null;

    protected string|Closure|null $unassignedRowLabel = null;

    protected bool $unassignedRowAlways = false;

    /** @var array<string, string> labels of the rows met while reading, by key */
    protected array $rowLabels = [];

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

    /**
     * Several records on one line: a task split into pieces (work that stops and
     * resumes later), a second period of the same work. `parent_id` is a nullable
     * column holding the key of another record of the same table, or a BelongsTo
     * relation to the same model. A record's line is its parent's key, or its own
     * when it has none: one level deep, a parent has no parent. The records of a
     * line share one row inside their group, overlapping ones stacked; each stays a
     * bar of its own (its moves, rules, actions and events).
     *
     * Dragging the first piece of a line moves the whole line, as in Microsoft
     * Project (see movePiecesAlone()); any other piece moves alone; a drag to another
     * group carries the whole line. Creating on a line's row adds a piece to it, with
     * the parent's key written to this attribute. The compact() layout ignores lines
     * (its rows are groups); preventLineOverlap() applies to both.
     */
    public function lineBy(?string $attribute): static
    {
        $this->lineAttribute = $attribute;
        $this->lineForeignKeyCache = null;

        return $this;
    }

    /**
     * Refuse a move, a resize or a new piece that would overlap another piece of the
     * same line (default: they stack in lanes). Checked on the server when a bar moves
     * and when the create and edit actions save, since the form can still change the
     * dates; the browser stops a drag at the neighbouring piece.
     */
    public function preventLineOverlap(bool|Closure $condition = true): static
    {
        $this->preventLineOverlap = $condition;
        $this->preventLineOverlapCache = null;

        return $this;
    }

    /** Dragging a line's first piece moves that piece alone, like any other (default: the whole line). */
    public function movePiecesAlone(bool $condition = true): static
    {
        $this->movePiecesAlone = $condition;

        return $this;
    }

    /**
     * Rows that hold several bars (issue #41): a machine's jobs, a person's tasks, a
     * room's bookings, side by side on one row, overlapping ones stacked in lanes. A
     * column (`room`) or a BelongsTo relation to another model (`machine`); the label
     * is a dotted path (`machine.name`) or `fn (Model $record): string`. Works under
     * groupBy() (a level above the rows) or without it. Without rows() the rows appear
     * in the order of their first bar; the bars without a row share one row at the end
     * of their group (see unassignedRow()).
     *
     * A bar dragged (or carried with ↑/↓) onto another row gets that row's key written,
     * in the same transaction as its dates; a click on a row creates a bar in it. Not
     * with compact() (its rows are groups) or lineBy().
     */
    public function rowBy(?string $attribute, string|Closure|null $label = null): static
    {
        $this->rowAttribute = $attribute;
        $this->rowLabel = $label;
        $this->rowForeignKeyCache = null;

        return $this;
    }

    /**
     * The rows, in order, empty ones included: a list of Row (see Row::fromModels())
     * or a closure returning one. A Row's group() puts it in its group; one without
     * a group shows in every group. A listed row stays whatever the filters leave.
     *
     * @param  list<Row>|Closure|null  $rows
     */
    public function rows(array|Closure|null $rows): static
    {
        $this->rows = $rows;
        $this->definedRowsCache = null;

        return $this;
    }

    /**
     * Refuse a move, a resize or a new bar that would overlap another bar of the same
     * row (a room booked twice, a machine on two jobs): default, they stack in lanes.
     * Checked across every group the row shows in, on the server when a bar moves and
     * when the create and edit actions save; the browser stops a drag at the next bar.
     * A bar without a row is never checked.
     */
    public function preventRowOverlap(bool|Closure $condition = true): static
    {
        $this->preventRowOverlap = $condition;
        $this->preventRowOverlapCache = null;

        return $this;
    }

    /**
     * Which rows a click may create in: `fn (string $row, ?string $group): bool`. By
     * default a row the chart knows: one listed in rows() or one a bar of the chart
     * is in; a related record that exists but has no row on the chart is refused.
     */
    public function creatableInRow(?Closure $callback): static
    {
        $this->creatableInRow = $callback;

        return $this;
    }

    /**
     * The row of the bars without a row (see rowBy()): a job nobody picked yet. By
     * default it is called "Unassigned" and shows at the end of a group while a bar of
     * that group has no row. Give it a name (`'No machine'`, or a closure returning one)
     * and it stays in every group, empty or not: a bar dropped on it is taken off its
     * row, a click on it creates a bar without one. `always: false` only renames it.
     */
    public function unassignedRow(string|Closure|null $label = null, bool $always = true): static
    {
        $this->unassignedRowLabel = $label;
        $this->unassignedRowAlways = $always;

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
     * Whether a bar may be dragged to another row (another group, or with rowBy()
     * another row): a bool or `fn (Model $record): bool`. Only on a chart grouped by a
     * column or a BelongsTo (or with rowBy()), and for a user authorizeMoves() allows;
     * `groups()`, `rows()` and the rows on the chart are the only targets.
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
     * Return false to refuse, or a string to refuse with that message. Then come the
     * group and the previous group, and with rowBy() the row and the previous row.
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
     * ?string $previousGroup, ?string $row, ?string $previousRow): void` (the groups only
     * when the chart is grouped, the rows only with rowBy()). Throw
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

    public function getLineAttribute(): ?string
    {
        return $this->lineAttribute;
    }

    /** Whether records may share a line (see lineBy()). */
    public function hasLines(): bool
    {
        return $this->lineAttribute !== null;
    }

    /** Whether the rows are lines: lineBy() outside the compact layout. */
    public function showsLines(): bool
    {
        return $this->hasLines() && ! $this->compact;
    }

    /** Evaluated once per instance, like readOnly(). */
    public function preventsLineOverlap(): bool
    {
        if (! $this->hasLines()) {
            return false;
        }

        return $this->preventLineOverlapCache ??= (bool) ($this->preventLineOverlap instanceof Closure ? app()->call($this->preventLineOverlap) : $this->preventLineOverlap);
    }

    public function movesPiecesAlone(): bool
    {
        return $this->movePiecesAlone;
    }

    /**
     * The attribute a piece's line is written to: the column, or the BelongsTo's foreign key.
     *
     * @internal
     *
     * @throws LogicException for a relation that is no BelongsTo to the same model
     */
    public function getLineForeignKey(): ?string
    {
        if ($this->lineAttribute === null) {
            return null;
        }

        if ($this->lineForeignKeyCache !== null) {
            return $this->lineForeignKeyCache;
        }

        $model = $this->newModel();

        if (! $model->isRelation($this->lineAttribute)) {
            return $this->lineForeignKeyCache = $this->lineAttribute;
        }

        $relation = $model->{$this->lineAttribute}();

        if (! $relation instanceof BelongsTo || $relation instanceof MorphTo || $relation->getRelated()::class !== $model::class) {
            throw new LogicException("The Gantt's lineBy() takes a column or a BelongsTo relation to the same model: [{$this->lineAttribute}] is neither. A line is keyed by its first record's own key.");
        }

        return $this->lineForeignKeyCache = $relation->getForeignKeyName();
    }

    /**
     * The line a record is on: its parent's key, or its own when it has none.
     *
     * @internal
     */
    public function lineKey(Model $record): ?string
    {
        if ($this->lineAttribute === null) {
            return null;
        }

        $parent = $record->getAttribute($this->getLineForeignKey());

        if ($parent instanceof BackedEnum) {
            $parent = $parent->value;
        }

        $key = $parent ?? $record->getKey();

        return $key === null || $key === '' ? null : (string) $key;
    }

    public function getRowAttribute(): ?string
    {
        return $this->rowAttribute;
    }

    /**
     * Whether the rows hold several bars (see rowBy()).
     *
     * @throws LogicException with compact() or lineBy(), which lay the rows out their own way
     */
    public function hasRows(): bool
    {
        if ($this->rowAttribute === null) {
            return false;
        }

        if ($this->compact) {
            throw new LogicException("The Gantt's rowBy() and compact() each decide what a row is: compact() makes a group one row, rowBy() puts rows inside the groups. Use one of them.");
        }

        if ($this->lineAttribute !== null) {
            throw new LogicException("The Gantt's rowBy() and lineBy() do not work on one chart yet (issue #41): use one of them.");
        }

        return true;
    }

    /** Evaluated once per instance, like readOnly(). */
    public function preventsRowOverlap(): bool
    {
        if (! $this->hasRows()) {
            return false;
        }

        return $this->preventRowOverlapCache ??= (bool) ($this->preventRowOverlap instanceof Closure ? app()->call($this->preventRowOverlap) : $this->preventRowOverlap);
    }

    /** The name of the row that holds the bars without a row (see unassignedRow()). */
    public function getUnassignedRowLabel(): string
    {
        $label = $this->unassignedRowLabel instanceof Closure ? app()->call($this->unassignedRowLabel) : $this->unassignedRowLabel;

        return filled($label) ? static::text($label) : __('packstub-gantt::gantt.unassigned');
    }

    /** Whether the row without a row shows in every group, empty or not (see unassignedRow()). */
    public function showsUnassignedRow(): bool
    {
        return $this->hasRows() && $this->unassignedRowAlways;
    }

    /**
     * Whether a bar's group may be written: the chart groups by a column or a
     * BelongsTo. On a chart with rowBy(), a drag to a row of another group needs it.
     */
    public function movesBetweenGroups(): bool
    {
        return $this->getGroupForeignKey() !== null;
    }

    /**
     * The attribute a bar's row is written to: the column, or the BelongsTo's foreign key.
     *
     * @internal
     *
     * @throws LogicException for a relation that is no BelongsTo to another model
     */
    public function getRowForeignKey(): ?string
    {
        if ($this->rowAttribute === null) {
            return null;
        }

        if ($this->rowForeignKeyCache !== null) {
            return $this->rowForeignKeyCache;
        }

        $relation = $this->rowRelation();

        return $this->rowForeignKeyCache = $relation === null ? $this->rowAttribute : $relation->getForeignKeyName();
    }

    /**
     * The rowBy() relation, or null for a column.
     *
     * @throws LogicException for anything but a BelongsTo to another model
     */
    protected function rowRelation(): ?BelongsTo
    {
        $model = $this->newModel();

        if ($this->rowAttribute === null || ! $model->isRelation($this->rowAttribute)) {
            return null;
        }

        $relation = $model->{$this->rowAttribute}();

        if (! $relation instanceof BelongsTo || $relation instanceof MorphTo) {
            throw new LogicException("The Gantt's rowBy() takes a column or a BelongsTo relation: [{$this->rowAttribute}] is neither. A row is keyed by the related record's key.");
        }

        if ($relation->getRelated()::class === $model::class) {
            throw new LogicException("The Gantt's rowBy() does not take a relation to the chart's own model yet: parent tasks as rows come with issue #41. For a task split into pieces, see lineBy().");
        }

        return $relation;
    }

    /**
     * The row a record is on: the column's value or the related record's key; null
     * when it has none (the bar sits on the unassigned row).
     *
     * @internal
     */
    public function rowKey(Model $record): ?string
    {
        if ($this->rowAttribute === null) {
            return null;
        }

        $value = $record->getAttribute($this->getRowForeignKey());

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return $value === null || $value === '' ? null : (string) $value;
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

    /** Whether this record may be dragged to another row: the chart groups (or rows, see rowBy()) by something writable, and the rules allow it. */
    public function isRegroupable(Model $record): bool
    {
        return ($this->getGroupForeignKey() !== null || $this->hasRows()) && ! $this->isReadOnly() && $this->canMoveRecord($record) && (bool) $this->evaluate($this->regroupable, $record);
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

    /**
     * Whether a new piece may join a line (a line's key, as the browser sends it from
     * the row that was clicked). Checked on the server whatever the browser sent: only
     * a line on this chart (a record of the chart's query without a parent, or the
     * parent of records in it), never a record that is itself a piece of another line.
     */
    public function canCreateOnLine(mixed $line): bool
    {
        if (! $this->canCreate() || ! $this->hasLines()) {
            return false;
        }

        if (! is_string($line) && ! is_int($line)) {
            return false;
        }

        $line = (string) $line;

        if ($line === '' || ! $this->keyShaped($line)) {
            return false;
        }

        $query = $this->baseQuery();
        $parent = $query->qualifyColumn($this->getLineForeignKey());
        $key = $query->getModel()->getQualifiedKeyName();

        // One level deep: a piece of another line is no line of its own.
        if ((clone $query)->where($key, $line)->whereNotNull($parent)->exists()) {
            return false;
        }

        return $query->where(fn (Builder $q) => $q
            ->where($parent, $line)
            ->orWhere(fn (Builder $root) => $root->where($key, $line)->whereNull($parent)))
            ->exists();
    }

    /**
     * Whether a new bar may be created in a row (see rowBy()), as the browser sends it
     * from the row that was clicked; null or '' is a bar without a row. Checked on the
     * server whatever the browser sent: an unknown key never reaches the record.
     */
    public function canCreateInRow(mixed $row, mixed $group = null): bool
    {
        if (! $this->canCreate() || ! $this->hasRows()) {
            return false;
        }

        if ($row === null || $row === '') {
            return true;
        }

        if (! is_string($row) && ! is_int($row)) {
            return false;
        }

        $row = (string) $row;

        if ($this->creatableInRow) {
            return (bool) ($this->creatableInRow)($row, is_string($group) && $group !== '' ? $group : null);
        }

        return $this->knowsRowKey($row);
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
        if (! is_int($id) && ! $this->keyShaped((string) $id)) {
            return null;
        }

        return array_key_exists($key = (string) $id, $this->records)
            ? $this->records[$key]
            : $this->records[$key] = $this->baseQuery()->whereKey($key)->first();
    }

    /**
     * Whether a string may be a key of the chart's model: an integer key never
     * matches 'abc', and asked anyway, PostgreSQL throws a QueryException.
     */
    protected function keyShaped(string $value): bool
    {
        return $this->newModel()->getKeyType() !== 'int' || ctype_digit($value);
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
            'lines' => $this->getLines($bars),
            'rows' => $this->getRows($bars),
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

        if ($this->hasRows() && $this->rowRelation() !== null && ! $this->rowLabel instanceof Closure && ! array_key_exists($this->rowAttribute, $query->getEagerLoads())) {
            $query->with($this->rowAttribute);
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
     * The rows in order (see rowBy()): the definition's (`listed`), whatever the bars,
     * then any met on a bar that was not listed, in order of first appearance. A listed
     * row without a group shows in every group; a row met on a bar only in the groups
     * of its bars.
     *
     * @internal
     *
     * @param  list<array<string, mixed>>  $bars
     * @return list<array{key: string, label: string, color: ?string, group: ?string, listed: bool}>
     */
    public function getRows(array $bars): array
    {
        if (! $this->hasRows()) {
            return [];
        }

        $rows = [];

        // Without groups there is one implicit group: a listed row's own group means nothing there.
        $grouped = $this->isGrouped();

        foreach ($this->definedRows() as $row) {
            // '' is the unassigned row's key, never a listed row's.
            if ($row->getKey() === '') {
                continue;
            }

            $rows[$row->getKey()] ??= [...$row->toArray(), ...($grouped ? [] : ['group' => null]), 'listed' => true];
        }

        foreach ($bars as $bar) {
            $key = (string) ($bar['row'] ?? '');

            if ($key !== '' && ! array_key_exists($key, $rows)) {
                $rows[$key] = [...Row::make($key)->label($this->rowLabels[$key] ?? null)->toArray(), 'group' => null, 'listed' => false];
            }
        }

        return array_values($rows);
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

        if ($this->hasLines()) {
            $bar['line'] = $this->lineKey($record);
        }

        if ($this->hasRows()) {
            $row = $this->rowKey($record);
            $bar['row'] = $row ?? '';

            if ($row !== null && ! isset($this->rowLabels[$row])) {
                $this->rowLabels[$row] = $this->rowLabelFor($record, $row);
            }
        }

        return $bar;
    }

    /**
     * The lines of the bars in a window that hold more than one piece, each with
     * every piece it has (inside the window or not: a line moves as a whole, and the
     * browser stops a drag at the next piece) and its label: its first record's, or,
     * when the chart's query does not show that record, its earliest piece's. Read
     * through baseQuery(), so a filter or the search never renames a line, and a
     * record the user may not see never names one.
     *
     * @internal
     *
     * @param  list<array<string, mixed>>  $bars
     * @return list<array{key: string, label: string, pieces: list<array<string, mixed>>}>
     */
    public function getLines(array $bars): array
    {
        if (! $this->hasLines()) {
            return [];
        }

        $keys = array_values(array_filter(
            array_unique(array_map(fn (array $bar) => (string) ($bar['line'] ?? ''), $bars)),
            fn (string $key) => $key !== '' && $this->keyShaped($key),
        ));

        if ($keys === []) {
            return [];
        }

        $query = $this->baseQuery();
        $parent = $query->qualifyColumn($this->getLineForeignKey());
        $key = $query->getModel()->getQualifiedKeyName();

        $records = $query
            ->where(fn (Builder $q) => $q
                ->whereIn($parent, $keys)
                ->orWhere(fn (Builder $root) => $root->whereIn($key, $keys)->whereNull($parent)))
            ->orderBy($key)
            ->limit(max(1, (int) config('filament-gantt.max_bars', 2000)))
            ->get();

        $wanted = array_flip($keys);
        $lines = [];

        foreach ($records as $record) {
            $line = $this->lineKey($record);

            if ($line !== null && isset($wanted[$line]) && ($piece = $this->pieceOf($record)) !== null) {
                $lines[$line][] = [$record, $piece];
            }
        }

        $out = [];

        foreach ($keys as $line) {
            if (count($lines[$line] ?? []) < 2) {
                continue;
            }

            $pieces = $this->inLineOrder($lines[$line], $line);
            $root = collect($pieces)->first(fn (array $piece) => $piece[1]['id'] === $line);

            $out[] = [
                'key' => $line,
                'label' => $this->labelOf(($root ?? $pieces[0])[0]),
                'pieces' => array_map(fn (array $piece) => $piece[1], $pieces),
            ];
        }

        return $out;
    }

    /**
     * What the browser needs of a piece of a line it may not draw: its dates, its row
     * and whether it may move with its line (the lock as presentBar() draws it).
     *
     * @return array<string, mixed>|null null when the record has no start
     */
    protected function pieceOf(Model $record): ?array
    {
        $span = $this->span($record);

        if ($span === null) {
            return null;
        }

        [$start, $end, $milestone] = $span;
        $lock = $this->getLockedBefore();

        return [
            'id' => (string) $record->getKey(),
            'group' => $this->groupKey($record) ?? '',
            'start' => $start->format('Y-m-d H:i'),
            'end' => $end->format('Y-m-d H:i'),
            'allDay' => $this->isDateOnly($record),
            'milestone' => $milestone,
            'movable' => $this->isMovable($record) && ! ($lock && $start < $lock),
            'regroupable' => $this->isRegroupable($record) && ! ($lock && $this->endedBefore($start, $end, $lock)),
        ];
    }

    /**
     * Pieces in the order of the line: by start, the line's own record first among
     * pieces starting together, then by key.
     *
     * @template T of array{0: Model, 1: array<string, mixed>}
     *
     * @param  list<T>  $pieces
     * @return list<T>
     */
    protected function inLineOrder(array $pieces, string $line): array
    {
        usort($pieces, fn (array $a, array $b) => [$a[1]['start'], $a[1]['id'] !== $line] <=> [$b[1]['start'], $b[1]['id'] !== $line]);

        return $pieces;
    }

    /** A record's label as its bar shows it (the bar() closure's, when it gives one). */
    protected function labelOf(Model $record): string
    {
        $label = $this->label === null ? (string) $record->getKey() : static::text($this->read($this->label, $record));

        $custom = $this->bar ? ($this->bar)($record) : null;

        if ($custom instanceof Bar && isset($custom->toArray()['label'])) {
            $label = static::text($custom->toArray()['label']);
        }

        return $label;
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
     * key, '' for the ungrouped row) the bar lands in that group too, and with a row
     * (rowBy(): the row's key, '' for no row) in that row. Returns the bar
     * as it looks afterwards. A refused move throws MoveRejected; an exception thrown
     * while saving (the database's, moveUsing()'s) propagates as it is.
     *
     * @return array<string, mixed>
     *
     * @throws MoveRejected
     */
    public function move(string $id, string $start, ?string $end = null, ?string $group = null, ?string $row = null): array
    {
        $record = $this->findRecord($id)
            ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));

        $plan = $this->planMove($record, $start, $end, $group, row: $row);

        if ($plan === null) {
            return $this->presentBar($record);
        }

        // One transaction from the save to the bar the browser gets back: when
        // afterMove(), a listener or the bar's presentation fails, the save is undone
        // too, so the browser putting the bar back matches the database. A MoveRejected
        // thrown here refuses with its message; anything else (a database error, a bug
        // in a closure) propagates for the caller to report: its message is not for
        // the user (see InteractsWithGantt::ganttMove()).
        return $record->getConnection()->transaction(fn () => $this->applyMove($plan));
    }

    /**
     * Move a whole line (see lineBy()): every piece of the line of bar $id, inside the
     * window or not, shifts by the amount that brings that bar to $start (whole days
     * when any piece is date-only); with a group (a row's key, '' for the ungrouped
     * row) every piece lands in that group too. Each piece is checked as a move of its
     * own (movable, the lock, canMove(), canRegroup()…), all of them before anything is
     * saved, so one refusal refuses the line; the pieces keep their places relative to
     * each other, so preventLineOverlap() has nothing to say. One transaction for the
     * line, a BarMoved per piece. Returns every piece's bar as it looks afterwards.
     *
     * @return list<array<string, mixed>>
     *
     * @throws MoveRejected
     */
    public function moveLine(string $id, string $start, ?string $group = null): array
    {
        $record = $this->findRecord($id)
            ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));

        // Lines move as lines only where the rows are lines (not in the compact layout).
        if (! $this->showsLines()) {
            throw new MoveRejected(__('packstub-gantt::gantt.failed'));
        }

        if ($this->isReadOnly()) {
            throw new MoveRejected(__('packstub-gantt::gantt.read_only'));
        }

        [$previousStart] = $this->span($record)
            ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));

        $line = (string) $this->lineKey($record);
        $pieces = $this->linePieces($line);
        $key = (string) $record->getKey();

        if (! $pieces->contains(fn (Model $piece) => (string) $piece->getKey() === $key)) {
            throw new MoveRejected(__('packstub-gantt::gantt.missing'));
        }

        // As in the browser: a line moves along its days only from its first piece, and
        // not at all with movePiecesAlone(); any piece carries it to another group.
        if ($group === null || $group === ($this->groupKey($record) ?? '')) {
            $first = $this->inLineOrder(
                $pieces
                    ->map(fn (Model $piece) => [$piece, ['id' => (string) $piece->getKey(), 'start' => ($this->span($piece)[0] ?? null)?->format('Y-m-d H:i')]])
                    ->filter(fn (array $piece) => $piece[1]['start'] !== null)
                    ->values()
                    ->all(),
                $line,
            )[0][1]['id'] ?? null;

            if ($this->movesPiecesAlone() || $first !== $key) {
                throw new MoveRejected(__('packstub-gantt::gantt.failed'));
            }
        }

        // Snapped relative to where the bar was, like a move; whole days as soon as one
        // piece has no hours to move, so no piece lands between two days.
        $unit = $pieces->contains(fn (Model $piece) => $this->isDateOnly($piece)) ? Unit::Day : $this->unit;
        $delta = $this->minutes($previousStart, $unit->snapFrom($this->parse($start), $previousStart));

        $plans = [];

        foreach ($pieces as $piece) {
            if (($span = $this->span($piece)) === null) {
                continue;
            }

            [$from, $until, $milestone] = $span;

            try {
                $plan = $this->planMove($piece, $from->addMinutes($delta), $milestone ? null : $until->addMinutes($delta), $group, alongLine: true);
            } catch (MoveRejected $e) {
                // The bar that was dragged says why; another piece is named.
                throw (string) $piece->getKey() === $key ? $e : new MoveRejected(__('packstub-gantt::gantt.line_part', ['label' => $this->labelOf($piece), 'reason' => $e->getMessage()]));
            }

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        return $record->getConnection()->transaction(function () use ($plans, $pieces) {
            $moved = [];

            foreach ($plans as $plan) {
                $moved[(string) $plan['record']->getKey()] = $this->applyMove($plan);
            }

            return $pieces
                ->map(fn (Model $piece) => $moved[(string) $piece->getKey()] ?? $this->presentBar($piece))
                ->filter()
                ->values()
                ->all();
        });
    }

    /**
     * Every rule of a move, checked without writing anything: what applyMove() is to
     * do, or null when the bar would stay as it is. Dates as the browser sends them
     * (strings) or already read; `$alongLine` when the piece moves with its whole line
     * (see moveLine()); `$row` the row it lands in (rowBy(): null keeps it, '' is no row).
     *
     * @return array<string, mixed>|null
     *
     * @throws MoveRejected
     */
    protected function planMove(Model $record, CarbonImmutable|string $start, CarbonImmutable|string|null $end, ?string $group, bool $alongLine = false, ?string $row = null): ?array
    {
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
        $newStart = $unit->snapFrom(is_string($start) ? $this->parse($start) : $start, $previousStart);
        $newEnd = $milestone
            ? $newStart
            : ($end === null ? $newStart->addMinutes($previousMinutes) : $unit->snapFrom(is_string($end) ? $this->parse($end) : $end, $previousEnd));

        // The row the bar lands in: the one it is in, unless the browser named another
        // ('' is the ungrouped row). Only a row that is on the chart, never a related
        // record that merely exists (see knowsGroup()).
        $previousGroup = $this->groupKey($record);
        $newGroup = $group === null ? $previousGroup : ($group === '' ? null : $group);
        $regroup = $newGroup !== $previousGroup;

        if ($regroup) {
            if (! $this->isRegroupable($record) || ! $this->movesBetweenGroups()) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_regroupable'));
            }

            if (! $this->knowsRow($group)) {
                throw new MoveRejected(__('packstub-gantt::gantt.unknown_group'));
            }
        }

        // rowBy(): the row it lands in, the same way (a listed row, or one a bar is in).
        $previousRow = $this->hasRows() ? $this->rowKey($record) : null;
        $newRow = $row === null || ! $this->hasRows() ? $previousRow : ($row === '' ? null : $row);
        $rowChange = $newRow !== $previousRow;

        if ($rowChange) {
            if (! $this->isRegroupable($record)) {
                throw new MoveRejected(__('packstub-gantt::gantt.not_regroupable'));
            }

            if (! $this->knowsRowKey($row)) {
                throw new MoveRejected(__('packstub-gantt::gantt.unknown_group'));
            }
        }

        $relocate = $regroup || $rowChange;

        if ($newStart->equalTo($previousStart) && $newEnd->equalTo($previousEnd) && ! $relocate) {
            return null;
        }

        $minutes = $this->minutes($newStart, $newEnd);
        $startChanged = ! $newStart->equalTo($previousStart);
        $endChanged = ! $newEnd->equalTo($previousEnd);

        // Classified by the edges, not by the duration alone: a bar that changed its
        // length and both its edges was moved as well as resized, and needs both rights.
        // A change of row (or group) alone needs neither: regroupable() decided above.
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

            if (($past && ($startChanged || $endChanged || $relocate)) || ($startChanged && ($newStart < $lock || $previousStart < $lock)) || ($endChanged && $newEnd < $lock)) {
                // A lock at midnight reads as a date; one during the day (now()) says the time too.
                $format = $lock->isStartOfDay() ? static::dateFormat() : static::dateTimeFormat();

                throw new MoveRejected(__('packstub-gantt::gantt.locked', ['date' => $lock->translatedFormat($format)]));
            }
        }

        // A piece moving with its whole line keeps its place among the others.
        if (! $alongLine && ($startChanged || $endChanged) && $this->preventsLineOverlap() && $this->overlapsLine($record, $newStart, $newEnd)) {
            throw new MoveRejected(__('packstub-gantt::gantt.overlaps'));
        }

        // rowBy(): a bar may not land on another bar of its row, the one it moves to included.
        if (($startChanged || $endChanged || $rowChange) && $newRow !== null && $this->preventsRowOverlap() && $this->overlapsRow($record, $newRow, $newStart, $newEnd)) {
            throw new MoveRejected(__('packstub-gantt::gantt.row_overlaps'));
        }

        // The closures and the event see the same wall-clock moments in the app's
        // timezone, the tag Eloquent gives a record's dates, so now() and isPast()
        // read naturally; the maths above and the save below keep the UTC-tagged ones.
        [$start, $end, $from, $until] = array_map($this->inAppTimezone(...), [$newStart, $newEnd, $previousStart, $previousEnd]);

        if ($regroup && $this->canRegroup) {
            $this->verdict(($this->canRegroup)($record, $newGroup, $previousGroup));
        }

        if ($this->canMove) {
            $this->verdict(($this->canMove)($record, $start, $end, $from, $until, $newGroup, $previousGroup, $newRow, $previousRow));
        }

        return compact('record', 'newStart', 'newEnd', 'milestone', 'previousStart', 'start', 'end', 'from', 'until', 'regroup', 'newGroup', 'previousGroup', 'rowChange', 'newRow', 'previousRow');
    }

    /**
     * Carry out a plan from planMove(): the save (or moveUsing()), BarMoved and
     * afterMove(). Runs inside the caller's transaction. Returns the bar as it looks
     * afterwards, read back through the chart's query, so what it adds (withCount,
     * joins, eager loads) is still on it; a record the move took out of the query is
     * presented as it is.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function applyMove(array $plan): array
    {
        ['record' => $record, 'start' => $start, 'end' => $end, 'from' => $from, 'until' => $until, 'newGroup' => $newGroup, 'previousGroup' => $previousGroup, 'newRow' => $newRow, 'previousRow' => $previousRow] = $plan;

        if ($this->moveUsing) {
            ($this->moveUsing)($record, $start, $end, $from, $until, $newGroup, $previousGroup, $newRow, $previousRow);
        } else {
            $this->store($record, $plan['newStart'], $plan['newEnd'], $plan['milestone'], $plan['previousStart'], $plan['regroup'] ? [$newGroup] : null, $plan['rowChange'] ? [$newRow] : null);
        }

        BarMoved::dispatch($record, $from, $until, $start, $end, $this->key, $previousGroup, $newGroup, $previousRow, $newRow);

        if ($this->afterMove) {
            ($this->afterMove)($record, $start, $end, $from, $until, $newGroup, $previousGroup, $newRow, $previousRow);
        }

        unset($this->records[(string) $record->getKey()]);

        $fresh = $this->baseQuery()->whereKey($record->getKey())->first() ?? $record->fresh() ?? $record;

        return $this->presentBar($fresh) ?? throw new MoveRejected(__('packstub-gantt::gantt.missing'));
    }

    /**
     * Whether a record's dates overlap another piece of its line: a saved record as it
     * is, or a new or edited one with the form's values filled in (its line is read
     * from it too). For an app's own forms when preventLineOverlap() is on.
     */
    public function overlapsOnLine(Model $record): bool
    {
        if (($span = $this->span($record)) === null) {
            return false;
        }

        return $this->overlapsLine($record, $span[0], $span[1]);
    }

    /** Whether [start, end) overlaps another piece of the record's line (a milestone inside a piece does; edges that touch do not). */
    protected function overlapsLine(Model $record, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $line = $this->lineKey($record);

        if ($line === null) {
            return false;
        }

        $self = $record->getKey() === null ? null : (string) $record->getKey();

        foreach ($this->linePieces($line) as $piece) {
            if ($self !== null && (string) $piece->getKey() === $self) {
                continue;
            }

            if (($span = $this->span($piece)) !== null && $span[0] < $end && $start < $span[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a record's dates overlap another bar of its row (see preventRowOverlap()):
     * a saved record as it is, or a new or edited one with the form's values filled in
     * (its row is read from it too). For an app's own forms; false without a row.
     */
    public function overlapsOnRow(Model $record): bool
    {
        if (($span = $this->span($record)) === null || ($row = $this->rowKey($record)) === null) {
            return false;
        }

        return $this->overlapsRow($record, $row, $span[0], $span[1]);
    }

    /**
     * Whether [start, end) overlaps another bar of a row (a milestone inside a bar does;
     * edges that touch do not), in any group: the row is the resource, a machine busy
     * for one project is busy for all. Only the bars near the range are read.
     */
    protected function overlapsRow(Model $record, string $row, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (! $this->rowKeyShaped($row)) {
            return false;
        }

        $query = $this->baseQuery();
        $startColumn = $query->qualifyColumn($this->startAttribute);
        $endColumn = $query->qualifyColumn($this->endAttribute);
        // Whole days around the range: a date-only end is stored as its last day, and
        // the exact test below decides.
        $first = $start->subDay()->toDateString();
        $after = $end->addDays(2)->toDateString();

        $query
            ->where($query->qualifyColumn($this->getRowForeignKey()), $row)
            ->whereNotNull($startColumn)
            ->where($startColumn, '<', $after)
            ->where(fn (Builder $q) => $q->whereNull($endColumn)->orWhere($endColumn, '>=', $first));

        if ($record->getKey() !== null) {
            $query->whereKeyNot($record->getKey());
        }

        foreach ($query->get() as $other) {
            if (($span = $this->span($other)) === null) {
                continue;
            }

            // The rule of overlapsLine(): a milestone strictly inside a bar overlaps it.
            if ($span[0] < $end && $start < $span[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every piece of a line the chart's query shows, in key order.
     *
     * @return Collection<int, Model>
     */
    protected function linePieces(string $line): Collection
    {
        if (! $this->hasLines() || $line === '' || ! $this->keyShaped($line)) {
            return new Collection;
        }

        $query = $this->baseQuery();
        $parent = $query->qualifyColumn($this->getLineForeignKey());
        $key = $query->getModel()->getQualifiedKeyName();

        return $query
            ->where(fn (Builder $q) => $q
                ->where($parent, $line)
                ->orWhere(fn (Builder $root) => $root->where($key, $line)->whereNull($parent)))
            ->orderBy($key)
            ->get()
            ->filter(fn (Model $piece) => $this->lineKey($piece) === $line)
            ->values();
    }

    /**
     * Turn a chart range into the values a create form should show for the start
     * and end attributes (a date-only record's end day is the day before the
     * chart's end), and the group, line and row the row that was clicked stands for.
     *
     * @internal
     *
     * @return array<string, string>
     */
    public function prefill(?string $start, ?string $end, ?string $group = null, ?string $line = null, ?string $row = null): array
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

        // The line the same way (see canCreateOnLine()): a new piece of a line on the chart, or nothing.
        if ($line !== null && $line !== '' && ($attribute = $this->getLineForeignKey()) && $this->canCreateOnLine($line)) {
            $data[$attribute] = $line;
        }

        // And the row (see canCreateInRow()).
        if ($row !== null && $row !== '' && $this->hasRows() && $this->canCreateInRow($row, $group)) {
            $data[$this->getRowForeignKey()] = $row;
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
     * Write the dates (and, when a one-element list is given, the group or the row) and save.
     *
     * @param  array{0: ?string}|null  $group
     * @param  array{0: ?string}|null  $row
     */
    protected function store(Model $record, CarbonImmutable $start, CarbonImmutable $end, bool $milestone, CarbonImmutable $previousStart, ?array $group = null, ?array $row = null): void
    {
        $dateOnly = $this->isDateOnly($record);

        if ($row !== null) {
            $record->setAttribute($this->getRowForeignKey(), $row[0]);
            // The loaded relation would still say the old row (its label).
            if ($record->isRelation($this->rowAttribute)) {
                $record->unsetRelation($this->rowAttribute);
            }
        }

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
     * Whether a row key names a row on the chart (see rowBy()): one listed in rows(),
     * or one a bar of the chart's query is in; '' (the unassigned row) when
     * unassignedRow() keeps it on the chart or there is a bar without a row. A related record that exists but has no row on the chart is not one.
     */
    protected function knowsRowKey(?string $row): bool
    {
        if (! $this->hasRows() || $row === null) {
            return false;
        }

        $query = $this->baseQuery();
        $column = $query->qualifyColumn($this->getRowForeignKey());

        if ($row === '') {
            return $this->unassignedRowAlways || $query->whereNull($column)->exists();
        }

        foreach ($this->definedRows() as $defined) {
            if ($defined->getKey() === $row) {
                return true;
            }
        }

        return $this->rowKeyShaped($row) && $query->where($column, $row)->exists();
    }

    /** Whether a string may be a key of the rowBy() relation's model (an integer key never matches 'abc'; PostgreSQL throws). */
    protected function rowKeyShaped(string $row): bool
    {
        $relation = $this->rowRelation();

        return $relation === null || $relation->getRelated()->getKeyType() !== 'int' || ctype_digit($row);
    }

    /**
     * The rows() list, evaluated once per instance.
     *
     * @return list<Row>
     */
    protected function definedRows(): array
    {
        if ($this->definedRowsCache !== null) {
            return $this->definedRowsCache;
        }

        $rows = $this->rows instanceof Closure ? app()->call($this->rows) : $this->rows;

        return $this->definedRowsCache = array_values(array_filter($rows ?? [], fn ($r) => $r instanceof Row));
    }

    /** A row's label as its first bar reads it: rowBy()'s label, the related record's name, or the value. */
    protected function rowLabelFor(Model $record, string $key): string
    {
        if ($this->rowLabel instanceof Closure) {
            return static::text(($this->rowLabel)($record));
        }

        if (is_string($this->rowLabel)) {
            return static::text(data_get($record, $this->rowLabel) ?? $key);
        }

        if ($record->isRelation($this->rowAttribute)) {
            $related = $record->getRelationValue($this->rowAttribute);

            foreach (['name', 'title', 'label'] as $attribute) {
                if ($related !== null && filled($related->getAttribute($attribute))) {
                    return (string) $related->getAttribute($attribute);
                }
            }

            return $key;
        }

        $value = $record->getAttribute($this->rowAttribute);

        return $value instanceof HasLabel ? (string) $value->getLabel() : $key;
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
