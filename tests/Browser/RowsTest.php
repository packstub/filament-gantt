<?php

use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\User;

/*
 * rowBy(): rows that hold several bars. A drag lands on a row, not on a group; a
 * row's other bars are walls; a split line travels to another row whole.
 */

beforeEach(function () {
    $this->website = project('Website');
    $this->ana = User::query()->firstOrFail();
    $this->bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'secret']);

    $this->rows = fn (Gantt $gantt) => $gantt
        ->query(fn () => Task::query()->with(['project', 'assignee']))
        ->rowBy('assignee', label: 'assignee.name');
});

it('carries a bar to another row, and keeps its dates', function () {
    $docs = task('Write docs', '2026-03-09', '2026-03-13', ['project_id' => $this->website->id, 'assignee_id' => $this->ana->id]);
    task('Ship beta', '2026-03-04', '2026-03-05', ['project_id' => $this->website->id, 'assignee_id' => $this->bob->id]);

    $chart = chart($this->rows);
    $chart->assertSee('Ana')->assertSee('Bob');

    $chart->drag($docs, toRow: 'Bob');

    expect($docs->fresh()->assignee_id)->toBe($this->bob->id)
        ->and($docs->fresh()->starts_on->toDateString())->toBe('2026-03-09')
        ->and($chart->bar($docs)['row'])->toEqual($this->bob->id);
    $chart->assertNoJavaScriptErrors();
});

it('stops a bar at the next bar of its row with preventRowOverlap()', function () {
    $docs = task('Write docs', '2026-03-09', '2026-03-11', ['project_id' => $this->website->id, 'assignee_id' => $this->ana->id]);
    task('Review', '2026-03-14', '2026-03-15', ['project_id' => $this->website->id, 'assignee_id' => $this->ana->id]);

    // Five days to the right would land on Review: it stops against it, two days on.
    chart(fn (Gantt $gantt) => ($this->rows)($gantt)->preventRowOverlap())->drag($docs, days: 5);

    expect($docs->fresh()->starts_on->toDateString())->toBe('2026-03-11')
        ->and($docs->fresh()->ends_on->toDateString())->toBe('2026-03-13');
});

it('refuses a drop on a taken part of another row, before anything is sent', function () {
    $docs = task('Write docs', '2026-03-09', '2026-03-13', ['project_id' => $this->website->id, 'assignee_id' => $this->ana->id]);
    task('Ship beta', '2026-03-10', '2026-03-12', ['project_id' => $this->website->id, 'assignee_id' => $this->bob->id]);

    $chart = chart(fn (Gantt $gantt) => ($this->rows)($gantt)->preventRowOverlap())->drag($docs, toRow: 'Bob', release: false);

    // The row says so while the bar is over it.
    expect($chart->script('document.querySelectorAll(".ps-gantt-row-blocked").length'))->toBe(1);

    $chart->drop()->assertSee('This would overlap another bar in the same row.');

    expect($docs->fresh()->assignee_id)->toBe($this->ana->id)
        ->and($chart->bar($docs)['row'])->toEqual($this->ana->id);
});

it('carries a split line to another row whole, its pieces still joined on one lane', function () {
    $first = task('Checkout', '2026-03-09', '2026-03-10', ['project_id' => $this->website->id, 'assignee_id' => $this->ana->id]);
    $second = task('Checkout, part 2', '2026-03-12', '2026-03-13', ['project_id' => $this->website->id, 'assignee_id' => $this->ana->id, 'parent_id' => $first->id]);
    task('Ship beta', '2026-03-04', '2026-03-05', ['project_id' => $this->website->id, 'assignee_id' => $this->bob->id]);

    $chart = chart(fn (Gantt $gantt) => ($this->rows)($gantt)->lineBy('parent_id'));

    // The dotted line between the two pieces.
    expect($chart->script('document.querySelectorAll(".ps-gantt-gap").length'))->toBe(1);

    // Grabbed by its second piece: the first goes along.
    $chart->drag($second, toRow: 'Bob');

    expect($first->fresh()->assignee_id)->toBe($this->bob->id)
        ->and($second->fresh()->assignee_id)->toBe($this->bob->id)
        ->and($first->fresh()->starts_on->toDateString())->toBe('2026-03-09')
        ->and($second->fresh()->starts_on->toDateString())->toBe('2026-03-12')
        ->and($chart->box($first)['y'])->toBe($chart->box($second)['y'])
        ->and($chart->script('document.querySelectorAll(".ps-gantt-gap").length'))->toBe(1);
    $chart->assertNoJavaScriptErrors();
});
