<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Packstub\Gantt\Events\BarMoved;
use Packstub\Gantt\Tests\Fixtures\PlainGantt;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    TaskGantt::$movable = null;
    TaskGantt::$readOnly = false;
    TaskGantt::$moved = [];
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('renders the chart with its state as JSON for the browser', function () {
    task('Write docs', '2026-10-05', '2026-10-07');

    Livewire::test(TaskGantt::class)
        ->assertSeeHtml('x-data="packstubGantt(')
        ->assertSeeHtml('packstub/filament-gantt/components/gantt.js')
        ->assertSee('Write docs')
        ->assertSeeHtml('wire:ignore');
});

it('queries and serialises the bars on the first render only: a re-render carries no state', function () {
    $task = task('Write docs', '2026-10-05', '2026-10-07');

    $component = Livewire::test(TaskGantt::class)->assertSee('Write docs');

    DB::enableQueryLog();
    $component
        ->mountAction('edit', ['ganttRecord' => (string) $task->id])
        ->set('mountedActions.0.data.title', 'Final docs')
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertDispatched('packstub-gantt-refresh')
        ->call('$refresh')
        ->assertSeeHtml('x-data="packstubGantt(')
        ->assertDontSee('Final docs');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql) => str_contains($sql, 'from "tasks"') && str_contains($sql, 'order by')))->toBeEmpty()
        ->and($component->instance()->ganttRendered)->toBeTrue()
        ->and($component->instance()->getGanttConfig())->toMatchArray(['bars' => [], 'groups' => [], 'readOnly' => false])
        ->and($component->instance()->getGanttConfig(withState: true)['bars'][0]['label'])->toBe('Final docs');
});

it('hands the browser everything it needs in the config', function () {
    $acme = project('Acme');
    task('A', '2026-10-05', '2026-10-07', ['project_id' => $acme->id]);

    $config = Livewire::test(TaskGantt::class)->instance()->getGanttConfig(withState: true);

    expect($config)->toMatchArray([
        'scale' => 'week',
        'unit' => 'day',
        'minMinutes' => 1440,
        'maxMinutes' => 30 * 1440,
        'firstDayOfWeek' => 1,
        'weekendDays' => [6, 7],
        'rowHeight' => 36,
        'grouped' => true,
        'compact' => false,
        'readOnly' => false,
        'today' => '2026-10-14',
        'height' => null,
        'barAction' => 'edit',
    ])
        ->and($config['key'])->toBe('gantt:'.TaskGantt::class.':guest')
        ->and($config['window'])->toBe(['start' => '2026-09-28', 'end' => '2026-10-25'])
        ->and($config['groups'][0])->toMatchArray(['key' => (string) $acme->id, 'label' => 'Acme', 'count' => 1])
        ->and($config['bars'][0]['label'])->toBe('A')
        ->and($config['pxPerDay'])->toBe(['day' => 48, 'week' => 18, 'month' => 5])
        ->and(array_column($config['filters'], 'type'))->toBe(['select', 'dateRange'])
        ->and($config['filters'][0]['options'])->toBe([['value' => (string) $acme->id, 'label' => 'Acme']])
        ->and(array_column($config['barActions'], 'name'))->toBe(['edit', 'delete', 'bump'])
        ->and($config['createAction']['name'])->toBe('create')
        ->and($config['i18n']['today'])->toBe('Today');
});

it('moves a bar without re-rendering and answers with the bar', function () {
    Event::fake([BarMoved::class]);
    $task = task('Build', '2026-10-05', '2026-10-07');

    Livewire::test(TaskGantt::class)
        ->call('ganttMove', (string) $task->id, '2026-10-12 00:00', '2026-10-15 00:00')
        ->assertReturned(fn ($result) => $result['ok'] === true && $result['bar']['start'] === '2026-10-12 00:00')
        ->assertNoRedirect();

    Event::assertDispatched(BarMoved::class);

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-12')
        ->and(TaskGantt::$moved)->toBe([[$task->id, '2026-10-05', '2026-10-12']]);
});

it('answers a refused move with the reason instead of an error', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');
    TaskGantt::$movable = ['Other'];

    Livewire::test(TaskGantt::class)
        ->call('ganttMove', (string) $task->id, '2026-10-12 00:00')
        ->assertReturned(fn ($result) => $result['ok'] === false && str_contains($result['message'], 'cannot be moved'));

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and(TaskGantt::$moved)->toBe([]);
});

it('ignores moves on a read-only chart', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');
    TaskGantt::$readOnly = true;

    $component = Livewire::test(TaskGantt::class)
        ->call('ganttMove', (string) $task->id, '2026-10-12 00:00')
        ->assertReturned(fn ($result) => $result['ok'] === false && str_contains($result['message'], 'read-only'));

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and($component->instance()->getGanttConfig())->toMatchArray(['readOnly' => true, 'createAction' => null])
        ->and($component->instance()->getGanttConfig(withState: true)['bars'][0])->toMatchArray(['movable' => false, 'resizable' => false]);
});

it('refreshes with filters and loads another window', function () {
    $acme = project('Acme');
    task('A', '2026-10-05', '2026-10-07', ['project_id' => $acme->id]);
    task('B', '2026-11-20', '2026-11-22');

    Livewire::test(TaskGantt::class)
        ->call('ganttRefresh', ['project_id' => (string) $acme->id])
        ->assertReturned(fn ($result) => array_column($result['bars'], 'label') === ['A'] && $result['groups'][0]['count'] === 1)
        ->call('ganttWindow', '2026-11-01', '2026-11-30')
        ->assertReturned(fn ($result) => $result['window'] === ['start' => '2026-11-01', 'end' => '2026-11-30'] && array_column($result['bars'], 'label') === ['B'])
        ->call('ganttWindow', null, null)
        ->assertReturned(fn ($result) => $result['window']['start'] === '2026-09-28');
});

it('offers no actions on a component without Filament\'s action system', function () {
    $config = Livewire::test(PlainGantt::class)->instance()->getGanttConfig(withState: true);

    expect($config['barActions'])->toBe([])
        ->and($config['createAction'])->toBeNull()
        ->and($config['grouped'])->toBeFalse();
});
