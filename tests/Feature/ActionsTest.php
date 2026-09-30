<?php

use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Group;
use Packstub\Gantt\Tests\Fixtures\Project;
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

it('treats an argument that is not a string, from a crafted request, as absent', function () {
    $task = task('A', '2026-10-05', '2026-10-07');

    Livewire::test(TaskGantt::class)
        ->mountAction('create', ['ganttStart' => ['x'], 'ganttEnd' => ['y'], 'ganttGroup' => ['1']])
        ->assertSet('mountedActions.0.data.starts_on', null)
        ->assertSet('mountedActions.0.data.ends_on', null);

    Livewire::test(TaskGantt::class)
        ->assertActionHidden('bump', ['ganttRecord' => [(string) $task->id]])
        ->assertActionHidden('bump', ['ganttRecord' => (int) $task->id])
        ->assertActionVisible('bump', ['ganttRecord' => (string) $task->id]);
});

it('runs a listed groups() closure once per request, whatever a create from a click asks', function () {
    $acme = project('Acme');
    task('A', '2026-10-01', '2026-10-02', ['project_id' => $acme->id]);

    $component = new class extends TaskGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->groups(fn () => Group::fromModels(Project::query()->get()));
        }
    };

    // The closure's own query, not the eager load of the bars' projects.
    $projects = fn () => collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => $sql === 'select * from "projects"')->count();

    DB::enableQueryLog();
    $test = Livewire::test($component::class);
    expect($projects())->toBe(1);

    DB::flushQueryLog();
    $test->mountAction('create', ['ganttStart' => '2026-10-06 00:00', 'ganttEnd' => '2026-10-09 00:00', 'ganttGroup' => (string) $acme->id]);
    expect($projects())->toBe(1);

    DB::flushQueryLog();
    $test->set('mountedActions.0.data.title', 'Once')->callMountedAction()->assertHasNoErrors();
    expect($projects())->toBe(1);
    DB::disableQueryLog();

    expect(Task::firstWhere('title', 'Once')->project_id)->toBe($acme->id);
});

it('opens the create form with the clicked spot filled in', function () {
    $acme = project('Acme');
    task('A', '2026-10-01', '2026-10-02', ['project_id' => $acme->id]); // the row that was clicked

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

it('never writes a group the chart does not know into the foreign key', function () {
    Livewire::test(TaskGantt::class)
        ->assertActionHidden('create', ['ganttStart' => '2026-10-06 00:00', 'ganttGroup' => '424242'])
        ->call('mountAction', 'create', ['ganttStart' => '2026-10-06 00:00', 'ganttGroup' => '424242']) // what a crafted request would send
        ->set('mountedActions.0.data.title', 'Crafted')
        ->call('callMountedAction');

    expect(Task::where('title', 'Crafted')->exists())->toBeFalse();
});

it('never creates under a related record that exists but has no row on the chart', function () {
    $acme = project('Acme');
    $other = project('Other'); // another tenant's, say: no bar of the chart is in it
    task('A', '2026-10-01', '2026-10-02', ['project_id' => $acme->id]);

    Livewire::test(TaskGantt::class)
        ->assertActionVisible('create', ['ganttStart' => '2026-10-06 00:00', 'ganttGroup' => (string) $acme->id])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-06 00:00', 'ganttGroup' => (string) $other->id])
        ->call('mountAction', 'create', ['ganttStart' => '2026-10-06 00:00', 'ganttGroup' => (string) $other->id]) // what a crafted request would send
        ->set('mountedActions.0.data.title', 'Parked')
        ->call('callMountedAction');

    expect(Task::where('title', 'Parked')->exists())->toBeFalse()
        ->and(Task::where('project_id', $other->id)->exists())->toBeFalse();
});

it('leaves a group field the user cleared alone', function () {
    $acme = project('Acme');
    task('A', '2026-10-01', '2026-10-02', ['project_id' => $acme->id]);

    $component = new class extends TaskGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->createAction(CreateAction::make()->schema([
                TextInput::make('title')->required(),
                Select::make('project_id')->options(fn () => Project::query()->pluck('name', 'id')->all()),
            ]));
        }
    };

    Livewire::test($component::class)
        ->mountAction('create', ['ganttStart' => '2026-10-06 00:00', 'ganttGroup' => (string) $acme->id])
        ->assertSet('mountedActions.0.data.project_id', (string) $acme->id)
        ->set('mountedActions.0.data.title', 'Loose')
        ->set('mountedActions.0.data.project_id', null)
        ->callMountedAction()
        ->assertHasNoErrors();

    $task = Task::firstWhere('title', 'Loose');

    expect($task->project_id)->toBeNull()
        ->and($task->starts_on->toDateString())->toBe('2026-10-06'); // the dates the form has no field for
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
