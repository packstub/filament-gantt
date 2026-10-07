<?php

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Packstub\Gantt\Bar;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;
use Packstub\Gantt\Tests\Fixtures\TaskPolicy;
use Packstub\Gantt\Tests\Fixtures\TaskResource\Pages\TasksGantt;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    TaskGantt::$movable = null;
    TaskGantt::$readOnly = false;
    TaskGantt::$bar = null;
    $this->actingAs(user());
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('refuses a move the resource policy does not allow, and draws the bar locked', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');
    TaskPolicy::$canUpdate = false;

    $test = Livewire::test(TasksGantt::class);

    $test->call('ganttMove', (string) $task->id, '2026-10-12 00:00')
        ->assertReturned(fn ($result) => $result === ['ok' => false, 'message' => 'You cannot move this bar.']);

    $bar = $test->instance()->getGanttConfig(withState: true)['bars'][0];

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and($bar['movable'])->toBeFalse()
        ->and($bar['resizable'])->toBeFalse()
        // the edit action is the resource's: its policy says no
        ->and($bar['actions'])->toBe([]);
});

it('asks the model policy for a move on any component, unless authorizeMoves() decides', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');
    TaskPolicy::$canUpdate = false;

    Livewire::test(TaskGantt::class)
        ->call('ganttMove', (string) $task->id, '2026-10-12 00:00')
        ->assertReturned(fn ($result) => $result['ok'] === false);

    $gantt = gantt();
    expect(fn () => $gantt->move((string) $task->id, '2026-10-12 00:00'))->toThrow('You cannot move this bar.')
        ->and(gantt()->authorizeMoves(true)->move((string) $task->id, '2026-10-12 00:00')['start'])->toBe('2026-10-12 00:00')
        ->and(gantt()->authorizeMoves(fn (Task $task) => $task->title === 'Other')->presentBar($task->fresh())['movable'])->toBeFalse();
});

it('allows moves on a model without a policy', function () {
    $shift = shift('Early', '2026-10-05 08:00:00', '2026-10-05 12:00:00');

    expect(shifts()->move((string) $shift->id, '2026-10-06 08:00')['start'])->toBe('2026-10-06 08:00');
});

it('gives each bar the actions the user may run on its record', function () {
    $open = task('Open', '2026-10-05', '2026-10-07');
    $done = task('Done', '2026-10-05', '2026-10-07', ['progress' => 100]);

    $bars = collect(Livewire::test(TaskGantt::class)->instance()->getGanttConfig(withState: true)['bars'])->keyBy('label');

    // DeleteAction is hidden() for a finished task
    expect($bars['Open']['actions'])->toBe(['edit', 'delete', 'bump'])
        ->and($bars['Done']['actions'])->toBe(['edit', 'bump']);
});

it('narrows the actions to Bar::actions(), on the server too', function () {
    $task = task('Open', '2026-10-05', '2026-10-07');
    TaskGantt::$bar = fn (Task $task) => Bar::make()->actions(['edit']);

    $test = Livewire::test(TaskGantt::class);

    expect($test->instance()->getGanttConfig(withState: true)['bars'][0]['actions'])->toBe(['edit']);

    $test->assertActionVisible('edit', ['ganttRecord' => (string) $task->id])
        ->assertActionHidden('bump', ['ganttRecord' => (string) $task->id])
        ->call('mountAction', 'bump', ['ganttRecord' => (string) $task->id]) // what a crafted request would send
        ->assertActionNotMounted('bump');

    expect($task->fresh()->progress)->toBe(0);
});

it('offers the create action only to who may create', function () {
    task('Build', '2026-10-05', '2026-10-07');

    expect(Livewire::test(TasksGantt::class)->instance()->getGanttConfig()['createAction'])->toMatchArray(['name' => 'create']);

    TaskPolicy::$canCreate = false;

    expect(Livewire::test(TasksGantt::class)->instance()->getGanttConfig()['createAction'])->toBeNull();
});

it('falls back to the action name when a menu label needs a record', function () {
    task('Build', '2026-10-05', '2026-10-07');
    TaskGantt::$bar = null;

    $config = Livewire::test(TaskGantt::class)->instance()->getGanttConfig();

    expect(array_column($config['barActions'], 'name'))->toBe(['edit', 'delete', 'bump']);
});
