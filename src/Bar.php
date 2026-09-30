<?php

namespace Packstub\Gantt;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Illuminate\Contracts\Support\Arrayable;

/**
 * What a bar shows. Plain data drawn by the browser: a chart of a few hundred bars is
 * a few kilobytes of JSON instead of a few hundred Blade components. The dates and
 * the rules come from the Gantt definition; this shapes the rest.
 *
 * @implements Arrayable<string, mixed>
 */
class Bar implements Arrayable
{
    protected ?string $label = null;

    protected ?string $color = null;

    protected ?int $progress = null;

    protected ?string $tooltip = null;

    protected ?string $url = null;

    /** @var list<string>|null */
    protected ?array $actions = null;

    public static function make(): static
    {
        return new static;
    }

    public function label(?string $text): static
    {
        $this->label = $text;

        return $this;
    }

    /** A Tailwind-ish name (sky, amber, emerald…), a panel colour (primary, danger…), a Filament palette or any CSS colour. */
    public function color(string|array|BackedEnum|null $color): static
    {
        $this->color = static::colorName($color);

        return $this;
    }

    /** 0–100, drawn as a fill inside the bar. */
    public function progress(int|float|string|null $percent): static
    {
        $this->progress = $percent === null || $percent === '' ? null : (int) max(0, min(100, round((float) $percent)));

        return $this;
    }

    /** Extra text in the bar's tooltip, under the dates. */
    public function tooltip(?string $text): static
    {
        $this->tooltip = $text;

        return $this;
    }

    /** A URL the bar links to when there is no click action (Ctrl/⌘-click always opens it). */
    public function url(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * The chart's bar actions this bar offers, by name (default: all of them). The
     * server still checks each action's own rules when it is run.
     *
     * @param  list<string>  $names
     */
    public function actions(array $names): static
    {
        $this->actions = array_values($names);

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $bar = array_filter([
            'label' => $this->label,
            'color' => $this->color,
            'progress' => $this->progress,
            'tooltip' => $this->tooltip,
            'url' => $this->url,
        ], fn ($v) => $v !== null);

        // An empty list is meaningful here: this bar offers no actions.
        if ($this->actions !== null) {
            $bar['actions'] = $this->actions;
        }

        return $bar;
    }

    /** Filament colours are palettes (shade => value); the chart draws one tone, the 500 shade. */
    public static function colorName(mixed $color): ?string
    {
        if ($color instanceof HasColor) {
            $color = $color->getColor();
        } elseif ($color instanceof BackedEnum) {
            $color = $color->value;
        }

        if (is_array($color)) {
            return $color[500] ?? (array_values($color)[0] ?? null);
        }

        return $color === null ? null : (string) $color;
    }
}
