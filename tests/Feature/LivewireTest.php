<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Packstub\Gantt\Events\BarMoved;
use Packstub\Gantt\Exceptions\MoveRejected;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\PlainGantt;
use Packstub\Gantt\Tests\Fixtures\TabbedGantt;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $this->actingAs(user());
    TaskGantt::$movable = null;
    TaskGantt::$readOnly = false;
    TaskGantt::$bar = null;
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

it('renders the bars on the first render that includes the chart, not the first render of the component', function () {
    task('Tab bar', '2026-10-05', '2026-10-07');

    $component = Livewire::test(TabbedGantt::class)
        ->assertSee('The list')
        ->assertDontSeeHtml('x-data="packstubGantt(');

    expect($component->instance()->ganttRendered)->toBeFalse();

    // @js() writes every quote of the JSON as a unicode escape.
    $json = fn (string $fragment) => str_replace('"', chr(92).'u0022', $fragment);

    $component->set('tab', 'timeline')
        ->assertSeeHtml('x-data="packstubGantt(')
        ->assertSeeHtml($json('"window":{"start":"2026-09-28"'))
        ->assertSee('Tab bar');

    expect($component->instance()->ganttRendered)->toBeTrue();

    // From then on a re-render carries no state, as on any chart.
    $component->call('$refresh')
        ->assertSeeHtml('x-data="packstubGantt(')
        ->assertSeeHtml($json('"window":{"start":null'))
        ->assertDontSee('Tab bar');
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
        ->and($config['key'])->toBe('gantt:'.TaskGantt::class.':'.auth()->id())
        ->and($config['window'])->toBe(['start' => '2026-09-28', 'end' => '2026-10-25'])
        ->and($config['groups'][0])->toMatchArray(['key' => (string) $acme->id, 'label' => 'Acme', 'count' => 1])
        ->and($config['bars'][0]['label'])->toBe('A')
        ->and($config['pxPerDay'])->toBe(['hour' => 960, 'day' => 48, 'week' => 18, 'month' => 5])
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

it('keeps an exception thrown while saving out of the notification: reported, answered with a generic message', function () {
    Exceptions::fake();
    $task = task('Build', '2026-10-05', '2026-10-07');

    $component = new class extends TaskGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->moveUsing(function (Task $task, CarbonImmutable $start) {
                throw_if($start->isWeekend(), new MoveRejected('Not on a weekend.'));

                // What a database refusal (an exclusion constraint, a strict-mode date) looks like.
                throw new QueryException('sqlite', 'update "tasks" set "starts_on" = ? where "id" = ?', [$start->toDateString(), $task->id], new RuntimeException('constraint failed'));
            });
        }
    };

    Livewire::test($component::class)
        ->call('ganttMove', (string) $task->id, '2026-10-12 00:00')
        ->assertReturned(['ok' => false, 'message' => 'The change did not go through.'])
        ->call('ganttMove', (string) $task->id, '2026-10-10 00:00')
        ->assertReturned(['ok' => false, 'message' => 'Not on a weekend.']);

    Exceptions::assertReported(fn (QueryException $e) => str_contains($e->getMessage(), 'update "tasks"'));
    Exceptions::assertReportedCount(1);

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
        ->call('ganttRefresh', [], '2026-11-01', '2026-11-30')
        ->assertReturned(fn ($result) => $result['window'] === ['start' => '2026-11-01', 'end' => '2026-11-30'] && array_column($result['bars'], 'label') === ['B'] && ! isset($result['filters']))
        ->call('ganttRefresh', [], null, null, true)
        ->assertReturned(fn ($result) => $result['window']['start'] === '2026-09-28' && array_column($result['filters'], 'name') === ['project_id', 'starts_on']);
});

it('ignores a date-range filter value that is no calendar date (a hand-edited URL)', function () {
    task('A', '2026-10-05', '2026-10-07');
    task('B', '2026-10-20', '2026-10-22');

    Livewire::test(TaskGantt::class)
        ->call('ganttRefresh', ['starts_on' => ['from' => '2026-02-30']])
        ->assertReturned(fn ($result) => array_column($result['bars'], 'label') === ['A', 'B'])
        ->call('ganttRefresh', ['starts_on' => ['from' => '2026-10-10']])
        ->assertReturned(fn ($result) => array_column($result['bars'], 'label') === ['B']);
});

it('offers no actions on a component without Filament\'s action system', function () {
    $config = Livewire::test(PlainGantt::class)->instance()->getGanttConfig(withState: true);

    expect($config['barActions'])->toBe([])
        ->and($config['createAction'])->toBeNull()
        ->and($config['grouped'])->toBeFalse();
});
