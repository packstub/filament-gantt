<?php

namespace Packstub\Gantt;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Conditionable;
use UnitEnum;

/**
 * One row that holds several bars (see Gantt::rowBy()): a machine, a person, a room.
 * Rows are derived from the bars unless the definition lists them with `rows()`,
 * which also fixes their order and shows the empty ones (a machine with no job yet),
 * each in its group (`group()`), or in every group when it names none.
 *
 * @implements Arrayable<string, mixed>
 */
class Row implements Arrayable
{
    use Conditionable;

    protected string|Closure|null $label = null;

    protected mixed $color = null;

    protected string|int|Closure|null $group = null;

    final public function __construct(protected string $key) {}

    public static function make(string|int $key): static
    {
        return new static((string) $key);
    }

    /**
     * One row per model, keyed by the model's key. Labels come from the given
     * attribute (`name` by default); the group, when given, from an attribute of the
     * model (`department_id`) or `fn (Model $record): string|int|null`.
     *
     * @param  iterable<Model>  $records
     * @return list<static>
     */
    public static function fromModels(iterable $records, string $label = 'name', string|Closure|null $color = null, string|Closure|null $group = null): array
    {
        $rows = [];

        foreach ($records as $record) {
            $key = $group instanceof Closure ? $group($record) : ($group === null ? null : data_get($record, $group));

            $rows[] = static::make($record->getKey())
                ->label((string) data_get($record, $label))
                ->color($color instanceof Closure ? $color($record) : ($color === null ? null : data_get($record, $color)))
                ->group($key instanceof BackedEnum ? $key->value : $key);
        }

        return $rows;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The dot next to the row's name: whatever Bar::color() takes, or a closure
     * returning one. Anything that does not read as a colour is dropped.
     *
     * @param  string|array<int|string, string>|UnitEnum|Closure|null  $color
     */
    public function color(mixed $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * The group the row sits in, as groupBy() keys it (the column's value or the
     * related record's key). Without one, a listed row shows in every group.
     */
    public function group(string|int|Closure|null $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        $label = $this->evaluate($this->label);

        return (string) ($label ?? str($this->key)->headline());
    }

    public function getColor(): ?string
    {
        return Bar::colorName($this->evaluate($this->color));
    }

    public function getGroup(): ?string
    {
        $group = $this->evaluate($this->group);

        if ($group instanceof BackedEnum) {
            $group = $group->value;
        }

        return $group === null ? null : (string) $group;
    }

    /** @return array{key: string, label: string, color: ?string, group: ?string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->getLabel(),
            'color' => $this->getColor(),
            'group' => $this->getGroup(),
        ];
    }

    protected function evaluate(mixed $value): mixed
    {
        return $value instanceof Closure ? app()->call($value, ['row' => $this]) : $value;
    }
}
