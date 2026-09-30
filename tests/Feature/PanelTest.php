<?php

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Packstub\Gantt\GanttPlugin;
use Packstub\Gantt\Tests\Fixtures\PlanningPage;
use Packstub\Gantt\Tests\Fixtures\PlanningWidget;
use Packstub\Gantt\Tests\Fixtures\TaskPolicy;
use Packstub\Gantt\Tests\Fixtures\TaskResource;
use Packstub\Gantt\Tests\Fixtures\TaskResource\Pages\TasksGantt;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    PlanningWidget::$editable = false;
    $this->actingAs(user());
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('serves the resource page at the resource\'s route', function () {
    task('Write docs', '2026-10-05', '2026-10-07');

    expect(TaskResource::getUrl('gantt'))->toBe('http://localhost/admin/tasks/gantt');

    $this->get('/admin/tasks/gantt')
        ->assertOk()
        ->assertSee('x-data="packstubGantt(', false)
        ->assertSee('Write docs')
        ->assertSee('packstub/filament-gantt/components/gantt.js');
});

it('follows the resource policy for access', function () {
    TaskPolicy::$allowed = false;

    $this->get('/admin/tasks/gantt')->assertForbidden();

    expect(TasksGantt::canAccess())->toBeFalse();
});

it('moves a bar from the resource page', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');

    Livewire::test(TasksGantt::class)
        ->call('ganttMove', (string) $task->id, '2026-10-12 00:00')
        ->assertReturned(fn ($result) => $result['ok'] === true);

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-12');
});

it('serves a standalone page', function () {
    task('Plan', '2026-10-05', '2026-10-07');

    $this->get('/admin/planning')->assertOk()->assertSee('Plan')->assertSee('packstubGantt(');

    expect(Livewire::test(PlanningPage::class)->instance()->getGanttConfig()['scale'])->toBe('month');
});

it('renders the widget read-only and ignores moves until told otherwise', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');

    $widget = Livewire::test(PlanningWidget::class)
        ->assertSee('Planning')
        ->assertSee('Build')
        ->call('ganttMove', (string) $task->id, '2026-10-12 00:00')
        ->assertReturned(fn ($result) => $result['ok'] === false);

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and($widget->instance()->getGanttConfig())->toMatchArray(['readOnly' => true, 'height' => '24rem']);

    PlanningWidget::$editable = true;

    Livewire::test(PlanningWidget::class)
        ->call('ganttMove', (string) $task->id, '2026-10-12 00:00')
        ->assertReturned(fn ($result) => $result['ok'] === true);

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-12');
});

it('registers the plugin on the panel', function () {
    expect(filament('packstub-gantt'))->toBeInstanceOf(GanttPlugin::class)
        ->and(GanttPlugin::get()->getId())->toBe('packstub-gantt');
});
