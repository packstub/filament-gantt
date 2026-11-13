<?php

use Packstub\Gantt\Tests\Fixtures\Task;

/*
 * Filament's actions from the chart: the modal is a Livewire render of the page, and
 * the chart (wire:ignore) has to live through it and redraw from what was saved.
 */

/** A field of the open action's form (its id has a dot, which a bare selector would read as a class). */
function field(string $name): string
{
    return '[id="mountedActionSchema0.'.$name.'"]';
}

beforeEach(function () {
    $this->website = project('Website');
    $this->docs = task('Write docs', '2026-03-09', '2026-03-13', ['project_id' => $this->website->id]);
    $this->ship = task('Ship beta', '2026-03-10', '2026-03-12', ['project_id' => $this->website->id]);
});

it('opens the edit form on a click and redraws the bar from what was saved', function () {
    $chart = chart()->clickBar($this->docs);

    $chart->waitForText('Save changes');
    expect($chart->openModals())->toBe(1);

    $chart->assertValue(field('title'), 'Write docs')
        ->fill(field('title'), 'Write the docs')
        ->fill(field('ends_on'), '2026-03-16')
        ->submitModal();

    $chart->waitUntil('c.bars.some((b) => b.label === "Write the docs")')->settled();

    expect($chart->bar($this->docs))->toMatchArray(['label' => 'Write the docs', 'start' => '2026-03-09 00:00', 'end' => '2026-03-17 00:00'])
        ->and($this->docs->fresh()->ends_on->toDateString())->toBe('2026-03-16')
        // the modal's renders never reached the chart: same window, both bars, still draggable
        ->and($chart->ids())->toHaveCount(2);

    $chart->waitFor('[...document.querySelectorAll(".fi-modal-window")].every((modal) => modal.getClientRects().length === 0)', 'the modal to close')
        ->drag($this->docs, days: 1);

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-10');
    $chart->assertNoJavaScriptErrors();
});

it('creates a bar on the day that was clicked in an empty part of a row', function () {
    $chart = chart()->clickRow('Website', '2026-03-04');

    $chart->waitForText('Create task')
        ->assertValue(field('starts_on'), '2026-03-04')
        ->assertValue(field('ends_on'), '2026-03-04')
        ->fill(field('title'), 'Launch')
        ->submitModal();

    $chart->waitUntil('c.bars.length === 3')->settled();

    $launch = Task::query()->where('title', 'Launch')->sole();

    expect($launch->project_id)->toBe($this->website->id)
        ->and($launch->starts_on->toDateString())->toBe('2026-03-04')
        ->and($chart->bar($launch))->toMatchArray(['start' => '2026-03-04 00:00', 'end' => '2026-03-05 00:00']);
    $chart->assertSee('3 bars');
});

it('does not take the release of a drag for a click', function () {
    $chart = chart()->drag($this->docs, days: 2);

    // Neither the bar's own action nor the row's create form.
    expect($chart->openModals())->toBe(0)
        ->and($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-11');
});

it('runs an action from the bar\'s right-click menu', function () {
    $chart = chart()->clickBar($this->ship, button: 'right');

    $chart->waitFor('getComputedStyle(document.querySelector(".ps-gantt-menu")).display !== "none"', 'the bar menu to open');

    expect($chart->script('[...document.querySelectorAll(".ps-gantt-menu-item")].map((item) => item.textContent.trim())'))->toBe(['Edit', 'Delete']);

    $chart->click('.ps-gantt-menu-item:has-text("Delete")')
        ->waitForText('Are you sure you would like to do this?')
        ->submitModal();

    $chart->waitUntil('c.bars.length === 1')->settled();

    expect(Task::query()->pluck('title')->all())->toBe(['Write docs'])
        ->and($chart->bar($this->ship))->toBeNull();
    $chart->assertSee('1 bar');
});
