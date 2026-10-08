<?php

namespace Packstub\Gantt;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A filter in the chart's toolbar: a select (`Filter::select()`, the default) or a
 * pair of date pickers (`Filter::dateRange()`). Applied on the server; a select
 * ignores values it did not offer, unless it is searchable (its values come from a
 * search, so any key is a candidate: the query decides what it matches).
 */
class Filter
{
    public const SELECT = 'select';

    public const DATE_RANGE = 'dateRange';

    protected string|Closure|null $label = null;

    /** @var array<string|int, string>|Closure */
    protected array|Closure $options = [];

    protected ?Closure $query = null;

    protected bool|Closure $searchable = false;

    protected bool $preload = true;

    protected ?Closure $searchResults = null;

    protected ?Closure $optionLabel = null;

    protected int $optionsLimit = 50;

    final public function __construct(protected string $name, protected string $type = self::SELECT) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public static function select(string $name): static
    {
        return new static($name, self::SELECT);
    }

    /** From / until date inputs; by default they narrow `<name>` to the days between (either side may be left open). */
    public static function dateRange(string $name): static
    {
        return new static($name, self::DATE_RANGE);
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** @param  array<string|int, string>|Closure  $options */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * How a chosen value narrows the query. Default: `where(<name>, <value>)` for a
     * select; `where(<name>, >=, from)` and `where(<name>, <, the day after until)`
     * for a date range (the closure receives `['from' => ?string, 'until' => ?string]`).
     */
    public function query(Closure $callback): static
    {
        $this->query = $callback;

        return $this;
    }

    /**
     * Let the user type to find an option (many users, many rooms). With preload()
     * (the default) the options are sent with the chart and the search runs on the
     * server through getSearchResultsUsing(), or over the options' labels; without
     * preload nothing is listed until the user types.
     */
    public function searchable(bool|Closure $condition = true): static
    {
        $this->searchable = $condition;

        return $this;
    }

    /** Whether a searchable select sends its options with the chart (default) or waits for a search. */
    public function preload(bool $condition = true): static
    {
        $this->preload = $condition;

        return $this;
    }

    /** `fn (string $search): array<string|int, string>`, the options matching a search; default: the options whose label contains it. */
    public function getSearchResultsUsing(?Closure $callback): static
    {
        $this->searchResults = $callback;

        return $this;
    }

    /** `fn (string $value): ?string`, the label of a value that is not among the listed options (one restored from the URL). */
    public function getOptionLabelUsing(?Closure $callback): static
    {
        $this->optionLabel = $callback;

        return $this;
    }

    /** How many options a list or a search shows at most (default 50). */
    public function optionsLimit(int $limit): static
    {
        $this->optionsLimit = max(1, $limit);

        return $this;
    }

    public function isSearchable(): bool
    {
        return (bool) ($this->searchable instanceof Closure ? app()->call($this->searchable) : $this->searchable);
    }

    public function isPreloaded(): bool
    {
        return $this->preload;
    }

    public function getOptionsLimit(): int
    {
        return $this->optionsLimit;
    }

    /**
     * The options matching what the user typed, at most optionsLimit() of them.
     *
     * @return array<string|int, string>
     */
    public function getSearchResults(string $search): array
    {
        if ($this->searchResults) {
            $results = ($this->searchResults)($search);
        } else {
            $needle = mb_strtolower(trim($search));
            $results = array_filter($this->getOptions(), fn ($label) => $needle === '' || str_contains(mb_strtolower((string) $label), $needle));
        }

        return array_slice($results, 0, $this->optionsLimit, preserve_keys: true);
    }

    /** The label of a value: from the options, else from getOptionLabelUsing(), else null. */
    public function getOptionLabel(string|int $value): ?string
    {
        $options = $this->getOptions();

        if (array_key_exists($value, $options)) {
            return (string) $options[$value];
        }

        $label = $this->optionLabel ? ($this->optionLabel)((string) $value) : null;

        return $label === null ? null : (string) $label;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isDateRange(): bool
    {
        return $this->type === self::DATE_RANGE;
    }

    public function getLabel(): string
    {
        $label = $this->label instanceof Closure ? app()->call($this->label) : $this->label;

        return (string) ($label ?? str($this->name)->beforeLast('_id')->beforeLast('_at')->headline());
    }

    /** @return array<string|int, string> */
    public function getOptions(): array
    {
        return $this->options instanceof Closure ? app()->call($this->options) : $this->options;
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($this->isDateRange()) {
            $this->applyDateRange($query, $value);

            return;
        }

        if (blank($value) || (! is_string($value) && ! is_int($value))) {
            return;
        }

        // A plain select offers a fixed list and takes nothing else; a searchable one
        // takes any key, the way a search box would, and the query decides.
        if (! $this->isSearchable() && ! array_key_exists($value, $this->getOptions())) {
            return;
        }

        if ($this->query) {
            ($this->query)($query, $value);

            return;
        }

        $query->where($query->qualifyColumn($this->name), $value);
    }

    protected function applyDateRange(Builder $query, mixed $value): void
    {
        $from = static::date($value['from'] ?? null);
        $until = static::date($value['until'] ?? null);

        if ($from === null && $until === null) {
            return;
        }

        if ($this->query) {
            ($this->query)($query, ['from' => $from, 'until' => $until]);

            return;
        }

        $column = $query->qualifyColumn($this->name);

        // Plain ranges, never DATE(column): the column's index stays in use. The last
        // day is included by asking for less than the day after it, which reads a
        // date and a datetime column the same way.
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($until !== null) {
            $query->where($column, '<', CarbonImmutable::parse($until, 'UTC')->addDay()->toDateString());
        }
    }

    /**
     * A `Y-m-d` string naming a real calendar date, or null for anything else: the
     * browser cannot inject a raw value, and a rolled-over date from a hand-edited
     * URL (`2026-02-30`, which some databases refuse) never reaches the query.
     */
    public static function date(mixed $value): ?string
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && (int) $m[1] >= 1000
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? $value
                : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $searchable = ! $this->isDateRange() && $this->isSearchable();
        $options = $this->isDateRange() || ($searchable && ! $this->preload) ? [] : $this->getOptions();

        return [
            'name' => $this->name,
            'label' => $this->getLabel(),
            'type' => $this->type,
            'searchable' => $searchable,
            'optionsLimit' => $this->optionsLimit,
            'options' => collect($options)->take($this->optionsLimit)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])->values()->all(),
        ];
    }
}
