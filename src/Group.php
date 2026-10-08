<?php

namespace Packstub\Gantt;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Conditionable;
use UnitEnum;

/**
 * One row group (a swimlane): a project, an assignee, a room, a machine. Groups are
 * derived from the bars unless the definition lists them with `groups()`, which
 * also fixes their order and shows the empty ones (a room with no booking).
 *
 * @implements Arrayable<string, mixed>
 */
class Group implements Arrayable
{
    use Conditionable;

    protected string|Closure|null $label = null;

    protected mixed $color = null;

    protected bool|Closure $collapsed = false;

    final public function __construct(protected string $key) {}

    public static function make(string|int $key): static
    {
        return new static((string) $key);
    }

    /**
     * One group per model, keyed by the model's key. Labels come from the given
     * attribute (`name` by default).
     *
     * @param  iterable<Model>  $records
     * @return list<static>
     */
    public static function fromModels(iterable $records, string $label = 'name', string|Closure|null $color = null): array
    {
        $groups = [];

        foreach ($records as $record) {
            $groups[] = static::make($record->getKey())
                ->label((string) data_get($record, $label))
                ->color($color instanceof Closure ? $color($record) : ($color === null ? null : data_get($record, $color)));
        }

        return $groups;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Whatever Bar::color() takes: a Tailwind-ish name (gray, sky, amber, emerald…), a
     * panel colour (primary, success…), a Filament palette, an enum implementing
     * HasColor or a CSS colour; or a closure returning one.
     *
     * Anything else (an integer column read by fromModels(), say) is kept only if it
     * reads as a colour.
     *
     * @param  string|array<int|string, string>|UnitEnum|Closure|null  $color
     */
    public function color(mixed $color): static
    {
        $this->color = $color;

        return $this;
    }

    /** Start folded; the user can unfold it and the chart remembers. */
    public function collapsed(bool|Closure $condition = true): static
    {
        $this->collapsed = $condition;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        $label = $this->evaluate($this->label);

        return (string) ($label ?? ($this->key === '' ? __('packstub-gantt::gantt.ungrouped') : str($this->key)->headline()));
    }

    public function getColor(): ?string
    {
        return Bar::colorName($this->evaluate($this->color));
    }

    public function isCollapsed(): bool
    {
        return (bool) $this->evaluate($this->collapsed);
    }

    /** @return array{key: string, label: string, color: ?string, collapsed: bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->getLabel(),
            'color' => $this->getColor(),
            'collapsed' => $this->isCollapsed(),
        ];
    }

    protected function evaluate(mixed $value): mixed
    {
        return $value instanceof Closure ? app()->call($value, ['group' => $this]) : $value;
    }
}
