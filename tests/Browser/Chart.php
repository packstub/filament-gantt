<?php

namespace Packstub\Gantt\Tests\Browser;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Pest\Browser\Api\Webpage;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use PHPUnit\Framework\Assert;

use function Amp\delay;

/**
 * The chart in a real browser, driven the way a person drives it: a mouse that is
 * pressed, moved in steps and released, and keys pressed on a focused bar. What the
 * chart then holds is read from its own Alpine state, what was saved from the database.
 *
 * Pest's browser API has no bare mouse (only element-to-element drags), so the three
 * mouse calls go straight to Playwright's page channel; everything else is the API.
 *
 * @mixin Webpage
 */
class Chart
{
    private const CHART = "Alpine.\$data(document.querySelector('.ps-gantt'))";

    private Page $page;

    private string $guid;

    public function __construct(private object $webpage)
    {
        // visit() hands back a page that opens on its first call.
        $webpage->assertPresent('.ps-gantt');
        $opened = Closure::bind(fn () => $this->waitablePage, $webpage, $webpage::class)();
        $this->page = Closure::bind(fn () => $this->page, $opened, $opened::class)();
        $this->guid = Closure::bind(fn () => $this->guid, $this->page, Page::class)();

        // One size everywhere: a laptop's screen and CI's runner give the same pixels per day.
        $webpage->resize(1440, 900);
        $this->drawn();
    }

    /** Drawn (its script and stylesheet are loaded on request) and holding its state. Call it again after a reload. */
    public function drawn(): static
    {
        // The fonts too: a font swapped in after the bars were measured moves them under the mouse.
        return $this->waitFor('!! document.querySelector(".ps-gantt-scroll") && !! window.Alpine && '.self::CHART.'?.view?.width > 0 && document.fonts.status === "loaded"', 'the chart to draw')->settled();
    }

    /** Load the page again, as F5 does: the URL keeps what the chart wrote into it. */
    public function reload(): static
    {
        $this->page->reload();

        return $this->drawn();
    }

    /** Everything else is the page's: assertSee(), click(), fill()… */
    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->webpage->{$name}(...$arguments);

        return is_object($result) && str_starts_with($result::class, 'Pest\\Browser\\Api\\') ? $this : $result;
    }

    /* ------------------------------------------------------------------ reads */

    public function script(string $expression): mixed
    {
        return $this->page->evaluate($expression);
    }

    /** A bar as the chart holds it (start, end, group, row, label…), or null when it is not on the chart. */
    public function bar(Model|int|string $id): ?array
    {
        $bar = $this->script('JSON.parse(JSON.stringify('.self::CHART.'.bars.find((b) => String(b.id) === '.json_encode((string) $this->idOf($id)).') ?? null))');

        return is_array($bar) ? $bar : null;
    }

    /** The ids of the bars on the chart. @return list<string> */
    public function ids(): array
    {
        return array_map(strval(...), (array) $this->script(self::CHART.'.bars.map((b) => b.id)'));
    }

    /**
     * Where a bar is drawn, in viewport pixels: x, y, width, height. `hitAt`: the point
     * across it (0 to 1) the mouse is about to press, which must be the bar's own.
     */
    public function box(Model|int|string $id, string $inside = '', ?float $hitAt = null): array
    {
        $selector = $this->selector($id).($inside !== '' ? ' '.$inside : '');
        $this->script('document.querySelector('.json_encode($selector).').scrollIntoView({ block: "center", inline: "nearest", behavior: "instant" })');

        return $this->steady('document.querySelector('.json_encode($selector).')', $selector, $hitAt);
    }

    /**
     * Where an element is once it stays put: the same box on two reads a beat apart,
     * and (with `hitAt`) the element itself under that point of it, not a modal, a
     * toast or a bar lying over it. A slower machine is still laying the page out
     * when the test measures.
     */
    private function steady(string $element, string $what, ?float $hitAt = 0.5): array
    {
        $hit = $hitAt === null ? 'true' : '(() => { const h = document.elementFromPoint(r.x + r.width * '.$hitAt.', r.y + r.height / 2); return !! h && (h === el || el.contains(h)) })()';
        $read = '(() => { const el = '.$element.'; if (! el) return null; const r = el.getBoundingClientRect(); '
            .'return { x: r.x, y: r.y, width: r.width, height: r.height, hit: '.$hit.' } })()';
        $until = microtime(true) + 8;
        $last = null;

        while (true) {
            $box = $this->script($read);

            if (is_array($box) && $box['hit'] && $last === $box) {
                unset($box['hit']);

                return $box;
            }

            if (microtime(true) > $until) {
                Assert::fail("{$what} did not hold still under the mouse: ".json_encode($box));
            }

            $last = $box;
            delay(0.05);
        }
    }

    /** The row with this label, in viewport pixels. */
    public function rowBox(string $label): array
    {
        $row = '[...document.querySelectorAll(".ps-gantt-row")].find((r) => r.querySelector(".ps-gantt-label-text")?.textContent.trim() === '.json_encode($label).')';
        Assert::assertTrue((bool) $this->script('!! '.$row), "No row labelled {$label}.");

        // Not scrolled to (a drag may be under way) nor hit-tested (a dragged bar may lie over it).
        return $this->steady($row, "The row {$label}", null);
    }

    /** What a bar's place in the drawn rows is right now (ms since the epoch), mid-drag included. */
    public function drawnAt(Model|int|string $id): array
    {
        return (array) $this->script('(() => { const b = '.self::CHART.'.rowBar('.json_encode((string) $this->idOf($id)).'); return b ? { s: b.s, e: b.e } : null })()');
    }

    /** How many of Filament's modals are open (an action's form, a confirmation). */
    public function openModals(): int
    {
        return (int) $this->script('[...document.querySelectorAll(".fi-modal-window")].filter((modal) => modal.getClientRects().length > 0).length');
    }

    /** Submit the open modal: its form's button, or a confirmation's. */
    public function submitModal(): static
    {
        $this->webpage->click('.fi-modal-window button[type="submit"]');

        return $this;
    }

    public function pxPerDay(): float
    {
        return (float) $this->script(self::CHART.'.view.pxPerDay');
    }

    /* ------------------------------------------------------------------ mouse */

    /**
     * Press a bar (or one of its edges), move by whole days and/or onto another row,
     * and release. `hold` runs while the button is still down, for what a drag shows
     * before it is dropped; `release: false` leaves it down (then drop(), or Escape).
     */
    public function drag(Model|int|string $id, int $days = 0, ?string $edge = null, ?string $toRow = null, ?Closure $hold = null, bool $release = true, float $at = 0.5): static
    {
        $box = $edge ? $this->box($id, ".ps-gantt-handle-{$edge}", 0.5) : $this->box($id, hitAt: $at);
        // `at`: how far along the bar it is grabbed (a parent's span has its children lying over it).
        $x = $box['x'] + $box['width'] * ($edge ? 0.5 : $at);
        $y = $box['y'] + $box['height'] / 2;
        // In a right-to-left page time runs the other way.
        $dir = $this->script('getComputedStyle(document.querySelector(".ps-gantt")).direction') === 'rtl' ? -1 : 1;
        $toX = $x + $dir * $days * $this->pxPerDay();
        $toY = $toRow === null ? $y : (function () use ($toRow) {
            $row = $this->rowBox($toRow);

            return $row['y'] + $row['height'] / 2;
        })();

        $this->mouse('mouseMove', ['x' => $x, 'y' => $y]);
        $this->mouse('mouseDown');
        // What the press took hold of, when the chart took any (a read-only chart takes none).
        $grabbed = $this->script('(() => { const p = '.self::CHART.'.pointer; return p ? { id: String(p.bar.src?.id ?? p.bar.id), edge: p.edge, narrow: '.self::CHART.'.narrow(p.bar) } : null })()');
        if (is_array($grabbed)) {
            Assert::assertSame((string) $this->idOf($id), $grabbed['id'], 'The press took hold of another bar.');
            Assert::assertTrue($grabbed['narrow'] || $grabbed['edge'] === $edge, 'The press took hold of '.($grabbed['edge'] ?? 'the whole bar').' instead of '.($edge ?? 'the whole bar').'.');
        }
        $this->mouse('mouseMove', ['x' => $toX, 'y' => $toY, 'steps' => 12]);

        if ($hold) {
            $hold($this);
        }

        return $release ? $this->drop() : $this;
    }

    /** Release the mouse and wait for the server's answer. */
    public function drop(): static
    {
        $this->mouse('mouseUp');

        return $this->settled();
    }

    /** A plain click on a bar: pressed, a beat, released (the chart tells a click from a drag by the travel). */
    public function clickBar(Model|int|string $id, string $button = 'left', ?float $at = null): static
    {
        $box = $this->box($id, hitAt: $at ?? 0.1);
        $this->mouse('mouseMove', ['x' => $box['x'] + ($at === null ? min(20, $box['width'] / 2) : $box['width'] * $at), 'y' => $box['y'] + $box['height'] / 2]);
        $this->mouse('mouseDown', ['button' => $button]);
        $this->mouse('mouseUp', ['button' => $button]);

        return $this;
    }

    /** A click on the empty part of a row, at a day counted from the chart's first. */
    public function clickRow(string $label, string $date): static
    {
        $row = $this->rowBox($label);
        $x = (float) $this->script('(() => { const c = '.self::CHART.'; const canvas = document.querySelector(".ps-gantt-canvas").getBoundingClientRect(); return canvas.x + (Date.parse('.json_encode($date.'T00:00:00Z').') - c.view.start) / 86400000 * c.view.pxPerDay + c.view.pxPerDay / 2 })()');
        $shown = (array) $this->script('(() => { const r = document.querySelector(".ps-gantt-scroll").getBoundingClientRect(); return { from: r.x, to: r.right } })()');
        Assert::assertTrue($x > $shown['from'] && $x < $shown['to'], "{$date} is not on screen: pick a day the chart shows without scrolling.");
        $this->mouse('mouseMove', ['x' => $x, 'y' => $row['y'] + $row['height'] / 2]);
        $this->mouse('mouseDown');
        $this->mouse('mouseUp');

        return $this;
    }

    /* --------------------------------------------------------------- keyboard */

    /** Keys on a focused bar: 'ArrowRight', 'Shift+ArrowRight', 'Escape'… */
    public function keysOn(Model|int|string $id, string ...$keys): static
    {
        $this->script('document.querySelector('.json_encode($this->selector($id)).').focus()');

        foreach ($keys as $key) {
            $this->send('keyboardPress', ['key' => $key]);
        }

        return $this;
    }

    public function press(string $key): static
    {
        $this->send('keyboardPress', ['key' => $key]);

        return $this;
    }

    /* ------------------------------------------------------------------ waits */

    /** Nothing in the air: no move waiting for its answer, no keys waiting to be sent, no refresh running. */
    public function settled(): static
    {
        return $this->waitUntil('c.pending === 0 && ! c.keyboard && ! c.pointer && ! c.bars.some((b) => b._pending)');
    }

    /** Wait until an expression over the chart (`c`) holds. */
    public function waitUntil(string $expression): static
    {
        return $this->waitFor('(() => { const c = '.self::CHART.'; return '.$expression.' })()', $expression);
    }

    /**
     * Poll an expression until it is truthy. The pause is the event loop's, never a
     * sleep: the app answers the browser from this same process.
     */
    public function waitFor(string $expression, ?string $what = null, float $seconds = 8): static
    {
        $until = microtime(true) + $seconds;

        while (! $this->script('!! ('.$expression.')')) {
            if (microtime(true) > $until) {
                Assert::fail('Waited '.$seconds.' s for '.($what ?? $expression).'.');
            }

            delay(0.03);
        }

        return $this;
    }

    /* --------------------------------------------------------------- plumbing */

    private function selector(Model|int|string $id): string
    {
        return '.ps-gantt-bar[data-id="'.$this->idOf($id).'"]';
    }

    private function idOf(Model|int|string $id): int|string
    {
        return $id instanceof Model ? $id->getKey() : $id;
    }

    private function mouse(string $method, array $params = []): void
    {
        $this->send($method, $params);
    }

    private function send(string $method, array $params = []): void
    {
        foreach (Client::instance()->execute($this->guid, $method, $params) as $_) {
            // drained: the call is done when its answer arrives
        }
    }
}
