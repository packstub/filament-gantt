<?php

use Packstub\Gantt\Tests\Fixtures\PlanningWidget;
use Packstub\Gantt\Tests\Fixtures\TaskPolicy;

/*
 * The plugin's own pages and widget in a panel: the resource page (moves follow the
 * resource's canEdit()), and the dashboard widget (read-only unless said otherwise).
 */

beforeEach(function () {
    $this->docs = task('Write docs', '2026-03-09', '2026-03-13', ['project_id' => project('Website')->id]);
});

it('moves a bar on the resource page', function () {
    $chart = chart(path: '/admin/tasks/gantt')->drag($this->docs, days: 2);

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-11');
    $chart->assertNoJavaScriptErrors();
});

it('locks the bars of the resource page for a user who may not edit', function () {
    TaskPolicy::$canUpdate = false;

    $chart = chart(path: '/admin/tasks/gantt');

    expect($chart->script('document.querySelectorAll(".ps-gantt-bar-locked").length'))->toBe(1);

    $chart->drag($this->docs, days: 2);

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-09');
});

it('shows a read-only chart in the dashboard widget, and a movable one when the widget says so', function () {
    $chart = chart(path: '/admin');

    expect($chart->script('document.querySelector(".ps-gantt").classList.contains("ps-gantt-is-readonly")'))->toBeTrue();

    $chart->assertSee('Write docs')->drag($this->docs, days: 2)->assertNoJavaScriptErrors();

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-09');

    PlanningWidget::$editable = true;

    chart(path: '/admin')->drag($this->docs, days: 2);

    expect($this->docs->fresh()->starts_on->toDateString())->toBe('2026-03-11');
});
