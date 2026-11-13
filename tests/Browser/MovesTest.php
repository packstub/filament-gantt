<?php

use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\Task;

/*
 * Moves made with a real mouse and real keys, in a real browser, against the real
 * Blade view, Alpine and Livewire: what the Pest suite (the server) and the Node suite
 * (the chart's script against a fake DOM) each see only half of.
 */

beforeEach(function () {
    $this->website = project('Website');
    $this->mobile = project('Mobile app');
    $this->docs = task('Write docs', '2026-03-09', '2026-03-13', ['project_id' => $this->website->id]);
    $this->ship = task('Ship beta', '2026-03-10', '2026-03-12', ['project_id' => $this->mobile->id]);
});

it('draws the chart with its own script and stylesheet, and no script error', function () {
    $chart = chart();

    $chart->assertSee('Write docs')->assertSee('Mobile app')->assertSee('2 bars')->assertNoJavaScriptErrors();

    // The stylesheet is loaded on request (x-load-css): without it a bar is a plain block.
    expect($chart->script('getComputedStyle(document.querySelector(".ps-gantt-bar")).position'))->toBe('absolute')
        // five days wide, where the axis puts the 9th
        ->and(round($chart->box($this->docs)['width'] / $chart->pxPerDay()))->toBe(5.0)
        ->and($chart->bar($this->docs))->toMatchArray(['start' => '2026-03-09 00:00', 'end' => '2026-03-14 00:00']);
});

it('drags a bar to new dates and saves them', function () {
    $chart = chart()->drag($this->docs, days: 3);

    expect($chart->bar($this->docs))->toMatchArray(['start' => '2026-03-12 00:00', 'end' => '2026-03-17 00:00'])
        ->and($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-12')
        ->and($this->docs->fresh()->ends_on->toDateString())->toBe('2026-03-16');

    // And back, to the left: what a reload then draws is what was saved.
    $chart->drag($this->docs, days: -5)->reload();

    expect($chart->bar($this->docs))->toMatchArray(['start' => '2026-03-07 00:00', 'end' => '2026-03-12 00:00']);
    $chart->assertNoJavaScriptErrors();
});

it('resizes a bar by either edge', function () {
    $chart = chart()->drag($this->docs, days: 2, edge: 'end');

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-09')
        ->and($this->docs->fresh()->ends_on->toDateString())->toBe('2026-03-15')
        ->and($chart->bar($this->docs)['end'])->toBe('2026-03-16 00:00');

    $chart->drag($this->docs, days: 1, edge: 'start');

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-10')
        ->and($this->docs->fresh()->ends_on->toDateString())->toBe('2026-03-15');
});

it('shows the new dates under the pointer while a bar is dragged', function () {
    $chart = chart()->drag($this->docs, days: 2, release: false);

    expect($chart->script('document.querySelector(".ps-gantt-hint").textContent'))->toContain('Mar 11')
        ->and($chart->script('document.querySelector(".ps-gantt").classList.contains("ps-gantt-is-dragging")'))->toBeTrue()
        // nothing is sent before the release
        ->and($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-09');

    $chart->drop();

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-11')
        ->and($chart->script('document.querySelector(".ps-gantt").classList.contains("ps-gantt-is-dragging")'))->toBeFalse();
});

it('puts the bar back and says why when the server refuses the move', function () {
    $chart = chart(fn (Gantt $gantt) => $gantt->canMove(fn () => 'March is frozen.'))->drag($this->docs, days: 3);

    $chart->assertSee('March is frozen.');

    expect($chart->bar($this->docs))->toMatchArray(['start' => '2026-03-09 00:00', 'end' => '2026-03-14 00:00'])
        ->and($chart->drawnAt($this->docs)['s'])->toBe(strtotime('2026-03-09 00:00 UTC') * 1000)
        ->and($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-09');
});

it('abandons a drag on Escape: nothing moves, nothing is saved, no form opens', function () {
    $chart = chart()->drag($this->docs, days: 4, release: false)->press('Escape')->drop();

    expect($chart->drawnAt($this->docs)['s'])->toBe(strtotime('2026-03-09 00:00 UTC') * 1000)
        ->and($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-09')
        // the release lands on the row under the pointer: not a click that creates
        ->and($chart->openModals())->toBe(0);
});

it('carries a bar to another group', function () {
    $chart = chart()->drag($this->docs, toRow: 'Mobile app');

    expect($this->docs->fresh()->project_id)->toBe($this->mobile->id)
        ->and($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-09')
        ->and($chart->bar($this->docs)['group'])->toEqual($this->mobile->id);

    // Its row is now under the other group's header.
    expect($chart->box($this->docs)['y'])->toBeGreaterThan($chart->rowBox('Mobile app')['y']);
});

it('moves and resizes a bar with the arrow keys', function () {
    $chart = chart()->keysOn($this->docs, 'ArrowRight', 'ArrowRight')->settled();

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-11')
        ->and($this->docs->fresh()->ends_on->toDateString())->toBe('2026-03-15');

    $chart->keysOn($this->docs, 'Shift+ArrowRight')->settled();

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-11')
        ->and($this->docs->fresh()->ends_on->toDateString())->toBe('2026-03-16');

    // Escape before the keys are sent puts the bar back.
    $chart->keysOn($this->docs, 'ArrowLeft', 'Escape')->settled();

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-11')
        ->and($chart->drawnAt($this->docs)['s'])->toBe(strtotime('2026-03-11 00:00 UTC') * 1000);
});

it('leaves a bar that may not move where it is, and a read-only chart alone', function () {
    $chart = chart(fn (Gantt $gantt) => $gantt->movable(fn (Task $task) => $task->title !== 'Write docs')->regroupable(false))
        ->drag($this->docs, days: 3)
        ->drag($this->ship, days: 3);

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-09')
        ->and($this->ship->fresh()->starts_on->toDateString())->toBe('2026-03-13');

    $chart = chart(fn (Gantt $gantt) => $gantt->readOnly())->drag($this->ship, days: -2);

    expect($this->ship->fresh()->starts_on->toDateString())->toBe('2026-03-13')
        ->and($chart->drawnAt($this->ship)['s'])->toBe(strtotime('2026-03-13 00:00 UTC') * 1000);
});

it('drags the right way in a right-to-left panel', function () {
    app()->setLocale('ar');

    $chart = chart();

    expect($chart->script('getComputedStyle(document.querySelector(".ps-gantt")).direction'))->toBe('rtl');

    // Three days later is three days to the left.
    $before = $chart->box($this->docs)['x'];
    $chart->drag($this->docs, days: 3);

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-12')
        ->and($chart->box($this->docs)['x'])->toBeLessThan($before);
});

it('drops a bar whose row was scrolled out of the DOM mid-drag, released on an empty spot', function () {
    // Over 200 rows only the ones in view are drawn: a wheel scroll during a drag drops the dragged bar's row.
    Task::query()->insert(collect(range(1, 240))->map(fn ($i) => [
        'title' => 'Filler '.$i, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-10', 'project_id' => $this->website->id,
    ])->all());

    $chart = chart(fn (Gantt $gantt) => $gantt->sortBy('id'))->drag($this->docs, days: 6, release: false);

    $chart->script('(() => { const el = document.querySelector(".ps-gantt-scroll"); el.scrollTop = el.scrollHeight })()');
    $chart->waitFor('! document.querySelector(\'.ps-gantt-bar[data-id="'.$this->docs->id.'"]\')', 'the dragged bar to leave the DOM');

    // The release lands on an empty stretch of a row far below (the fillers end on the 10th): no bar hears it.
    // drop() waits for the chart to let go of the pointer, which it never did before.
    $chart->drop();

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-15')
        ->and($chart->script('document.querySelector(".ps-gantt").classList.contains("ps-gantt-is-dragging")'))->toBeFalse();
    $chart->assertNoJavaScriptErrors();
});
