<?php

use Packstub\Gantt\Enums\ParentSpan;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\Task;

/*
 * rowBy('parent'): a task is a row, its own dates a span across it, its subtasks the
 * bars. The span drags its subtasks along while it moves, not only when it lands.
 */

const DAY_MS = 86400000;

beforeEach(function () {
    $this->website = project('Website');
    $this->checkout = task('Checkout', '2026-03-05', '2026-03-16', ['project_id' => $this->website->id]);
    $this->design = task('Design', '2026-03-05', '2026-03-06', ['project_id' => $this->website->id, 'parent_id' => $this->checkout->id]);
    $this->build = task('Build', '2026-03-07', '2026-03-09', ['project_id' => $this->website->id, 'parent_id' => $this->checkout->id]);

    $this->parents = fn (Gantt $gantt) => $gantt->rowBy('parent')->parentSpan(ParentSpan::Own);
});

it('draws a parent as a row with its span, its subtasks on it', function () {
    $chart = chart($this->parents);

    expect($chart->script('document.querySelectorAll(".ps-gantt-bar-span").length'))->toBe(1)
        ->and($chart->bar($this->checkout))->toMatchArray(['parent' => true, 'start' => '2026-03-05 00:00', 'end' => '2026-03-17 00:00'])
        ->and($chart->bar($this->design)['row'])->toEqual($this->checkout->id)
        // twelve days of span, the subtasks inside it
        ->and(round($chart->box($this->checkout)['width'] / $chart->pxPerDay()))->toBe(12.0)
        ->and($chart->box($this->design)['x'])->toBe($chart->box($this->checkout)['x']);
    $chart->assertNoJavaScriptErrors();
});

it('moves the subtasks with the span while it is dragged, and saves them all', function () {
    $chart = chart($this->parents);
    $before = $chart->drawnAt($this->build);

    $chart->drag($this->checkout, days: 2, at: 0.6, hold: function ($chart) use ($before) {
        expect($chart->drawnAt($this->build)['s'])->toBe($before['s'] + 2 * DAY_MS)
            ->and($chart->drawnAt($this->design)['e'])->toBe(strtotime('2026-03-09 00:00 UTC') * 1000);
    });

    expect(Task::query()->orderBy('id')->get()->map(fn (Task $task) => $task->starts_on->toDateString().' '.$task->ends_on->toDateString())->all())
        ->toBe(['2026-03-07 2026-03-18', '2026-03-07 2026-03-08', '2026-03-09 2026-03-11']);
    $chart->assertNoJavaScriptErrors();
});

it('moves a span as a parent even when it is the first piece of a split line', function () {
    // Checkout resumes later: a second piece on its line, which stays where it is.
    $later = task('Checkout, phase 2', '2026-03-17', '2026-03-18', ['project_id' => $this->website->id, 'split_of_id' => $this->checkout->id]);

    $chart = chart(fn (Gantt $gantt) => ($this->parents)($gantt)->lineBy('split_of_id'));
    $before = $chart->drawnAt($this->build);

    $chart->drag($this->checkout, days: -2, at: 0.6, hold: function ($chart) use ($before) {
        // Before the fix the subtasks only arrived on release.
        expect($chart->drawnAt($this->build)['s'])->toBe($before['s'] - 2 * DAY_MS);
    });

    expect($this->checkout->fresh()->starts_on->toDateString())->toBe('2026-03-03')
        ->and($this->design->fresh()->starts_on->toDateString())->toBe('2026-03-03')
        ->and($this->build->fresh()->starts_on->toDateString())->toBe('2026-03-05')
        ->and($later->fresh()->starts_on->toDateString())->toBe('2026-03-17');

    // The arrow keys agree with the drag.
    $chart->keysOn($this->checkout, 'ArrowRight')->settled();

    expect($this->checkout->fresh()->starts_on->toDateString())->toBe('2026-03-04')
        ->and($this->build->fresh()->starts_on->toDateString())->toBe('2026-03-06')
        ->and($later->fresh()->starts_on->toDateString())->toBe('2026-03-17');
});

it('stops a subtask at its parent\'s dates with keepInsideParent()', function () {
    $chart = chart(fn (Gantt $gantt) => ($this->parents)($gantt)->keepInsideParent());

    // Twelve days to the right would leave Checkout: Build stops at its last day.
    $chart->drag($this->build, days: 12);

    expect($this->build->fresh()->starts_on->toDateString())->toBe('2026-03-14')
        ->and($this->build->fresh()->ends_on->toDateString())->toBe('2026-03-16');
});

it('stops a parent\'s edge at its subtasks with keepInsideParent()', function () {
    $chart = chart(fn (Gantt $gantt) => ($this->parents)($gantt)->keepInsideParent());

    // Ten days shorter would leave Build outside: the span's end stops at Build's last day.
    $chart->drag($this->checkout, days: -10, edge: 'end');

    expect($this->checkout->fresh()->starts_on->toDateString())->toBe('2026-03-05')
        ->and($this->checkout->fresh()->ends_on->toDateString())->toBe('2026-03-09')
        ->and($this->build->fresh()->ends_on->toDateString())->toBe('2026-03-09');
});

it('creates a subtask under the parent whose span was clicked', function () {
    $chart = chart($this->parents)->clickBar($this->checkout, at: 0.6);

    $chart->waitForText('Create task')
        ->fill('[id="mountedActionSchema0.title"]', 'Test')
        ->submitModal();

    $chart->waitUntil('c.bars.length === 4')->settled();

    expect(Task::query()->where('title', 'Test')->sole()->parent_id)->toBe($this->checkout->id);
});
