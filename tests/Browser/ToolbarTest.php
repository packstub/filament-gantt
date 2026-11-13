<?php

use Packstub\Gantt\Enums\FiltersLayout;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\Project;

/*
 * The toolbar: the state is the browser's (the URL keeps it), the fields are
 * Filament's own form components, and the two have to agree after a reload.
 */

beforeEach(function () {
    $this->website = project('Website');
    $this->mobile = project('Mobile app');
    $this->docs = task('Write docs', '2026-03-09', '2026-03-13', ['project_id' => $this->website->id]);
    $this->ship = task('Ship beta', '2026-03-10', '2026-03-12', ['project_id' => $this->mobile->id]);
});

it('searches as you type, and a reload keeps the search', function () {
    $chart = chart(fn (Gantt $gantt) => $gantt->searchable(['title']));

    $chart->fill('.ps-gantt-search input', 'beta');
    $chart->waitUntil('c.bars.length === 1')->settled();

    expect($chart->ids())->toBe([(string) $this->ship->id]);
    $chart->assertQueryStringHas('search', 'beta')->assertSee('1 bar')->assertDontSee('Write docs');

    $chart->reload()->waitUntil('c.bars.length === 1');

    expect($chart->ids())->toBe([(string) $this->ship->id])
        ->and($chart->value('.ps-gantt-search input'))->toBe('beta');
});

it('switches the scale, and a reload keeps it', function () {
    $chart = chart();
    $day = $chart->pxPerDay();

    $chart->click('.ps-gantt-toolbar .fi-tabs-item:has-text("Month")');
    $chart->waitUntil('c.scale === "month"')->settled();

    expect($chart->pxPerDay())->toBeLessThan($day);
    $chart->assertQueryStringHas('scale', 'month');

    $chart->reload();

    expect($chart->script('Alpine.$data(document.querySelector(".ps-gantt")).scale'))->toBe('month')
        ->and($chart->ids())->toHaveCount(2);
    $chart->assertNoJavaScriptErrors();
});

it('filters through Filament\'s own select, and shows the filter in the field after a reload', function () {
    $define = fn (Gantt $gantt) => $gantt
        ->filters([Filter::select('project_id')->options(fn () => Project::query()->pluck('name', 'id')->all())])
        ->filtersLayout(FiltersLayout::AboveContent);

    $chart = chart($define);
    $chart->select('.ps-gantt-filters-above select', (string) $this->mobile->id);
    $chart->waitUntil('c.bars.length === 1')->settled();

    expect($chart->ids())->toBe([(string) $this->ship->id]);
    // (PHP reads a dot in a query key as an underscore, so the URL itself is read)
    expect($chart->url())->toContain('f.project_id='.$this->mobile->id);

    // The URL restores the chart's state, and the chart writes it back into the field.
    $chart->reload()->waitUntil('c.bars.length === 1');
    $chart->waitFor('document.querySelector(".ps-gantt-filters-above select").value === '.json_encode((string) $this->mobile->id), 'the select to show the filter');

    expect($chart->ids())->toBe([(string) $this->ship->id]);

    // Reset: every bar again, the field empty.
    $chart->click('.ps-gantt-filters-above .ps-gantt-filters-reset');
    $chart->waitUntil('c.bars.length === 2')->settled();
    $chart->waitFor('document.querySelector(".ps-gantt-filters-above select").value === ""', 'the select to clear');
    $chart->assertNoJavaScriptErrors();
});
