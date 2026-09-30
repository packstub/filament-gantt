<?php

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    TaskGantt::$movable = null;
    TaskGantt::$readOnly = false;
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('edits a bar in a modal and tells the chart to refresh', function () {
    $task = task('Draft', '2026-10-05', '2026-10-07');

    Livewire::test(TaskGantt::class)
        ->mountAction('edit', ['ganttRecord' => (string) $task->id])
        ->assertSet('mountedActions.0.data.title', 'Draft')
        ->set('mountedActions.0.data.title', 'Final')
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertDispatched('packstub-gantt-refresh');

    expect($task->fresh()->title)->toBe('Final');
});

it('runs plain bar actions on the bar\'s record, with the app\'s own visibility rules', function () {
    $task = task('Low', '2026-10-05', '2026-10-07', ['progress' => 10]);
    $done = task('Done', '2026-10-05', '2026-10-07', ['progress' => 100]);

    Livewire::test(TaskGantt::class)
        ->callAction('bump', arguments: ['ganttRecord' => (string) $task->id])
        ->assertActionHidden('delete', ['ganttRecord' => (string) $done->id])
        ->callAction('delete', arguments: ['ganttRecord' => (string) $task->id]);

    expect(Task::find($task->id))->toBeNull()
        ->and($done->fresh())->not->toBeNull();
});

it('never runs a bar action on a record outside the chart', function () {
    $task = task('Hidden', '2026-10-05', '2026-10-07');

    $component = new class extends TaskGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->query(fn () => Task::query()->where('title', '!=', 'Hidden'));
        }
    };

    Livewire::test($component::class)
        ->assertActionHidden('bump', ['ganttRecord' => (string) $task->id])
        ->call('mountAction', 'bump', ['ganttRecord' => (string) $task->id]) // what a crafted request would send
        ->call('callMountedAction')
        ->assertNotDispatched('packstub-gantt-refresh');

    expect($task->fresh()->progress)->toBe(0);
});

it('opens the create form with the clicked spot filled in', function () {
    $acme = project('Acme');

    Livewire::test(TaskGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-06 00:00', 'ganttEnd' => '2026-10-09 00:00', 'ganttGroup' => (string) $acme->id])
        ->assertSet('mountedActions.0.data.starts_on', '2026-10-06')
        ->assertSet('mountedActions.0.data.ends_on', '2026-10-08')
        ->set('mountedActions.0.data.title', 'Fresh')
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertDispatched('packstub-gantt-refresh');

    $task = Task::firstWhere('title', 'Fresh');

    expect($task->starts_on->toDateString())->toBe('2026-10-06')
        ->and($task->ends_on->toDateString())->toBe('2026-10-08')
        ->and($task->project_id)->toBe($acme->id);
});

it('keeps the dates the user typed over the clicked spot', function () {
    Livewire::test(TaskGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-06 00:00', 'ganttEnd' => '2026-10-09 00:00'])
        ->set('mountedActions.0.data.title', 'Typed')
        ->set('mountedActions.0.data.starts_on', '2026-11-01')
        ->set('mountedActions.0.data.ends_on', '2026-11-03')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect(Task::firstWhere('title', 'Typed')->starts_on->toDateString())->toBe('2026-11-01');
});

it('refuses to create on a read-only chart', function () {
    TaskGantt::$readOnly = true;

    Livewire::test(TaskGantt::class)
        ->assertActionHidden('create', ['ganttStart' => '2026-10-06 00:00'])
        ->call('mountAction', 'create', ['ganttStart' => '2026-10-06 00:00'])
        ->set('mountedActions.0.data.title', 'Sneaky')
        ->call('callMountedAction');

    expect(Task::where('title', 'Sneaky')->exists())->toBeFalse();
});
