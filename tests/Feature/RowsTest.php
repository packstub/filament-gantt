<?php

use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Packstub\Gantt\Events\BarMoved;
use Packstub\Gantt\Exceptions\MoveRejected;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Row;
use Packstub\Gantt\Tests\Fixtures\RowGantt;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;
use Packstub\Gantt\Tests\Fixtures\User;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $this->actingAs(user());
    TaskGantt::$movable = null;
    TaskGantt::$readOnly = false;
    TaskGantt::$moved = [];
    RowGantt::$preventOverlap = false;
    RowGantt::$rows = null;
    RowGantt::$creatableInRow = null;
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Someone tasks are assigned to: a row with rowBy('assignee'). */
function person(string $name): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'secret']);
}

/** A chart of tasks grouped by project, one row per assignee. */
function rowsGantt(): Gantt
{
    return gantt()->query(fn () => Task::query()->with(['project', 'assignee']))->rowBy('assignee');
}

/** The message a refused move gives. */
function rowRefusal(Closure $move): string
{
    try {
        $move();
    } catch (MoveRejected $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('The move was not refused.');
}

const ROW_OVERLAPS = 'This would overlap another bar in the same row.';

/* ------------------------------------------------------------------ reading */

it('puts each bar on its row, and names the rows met on the bars after the related record', function () {
    $bo = person('Bo');
    $cy = person('Cy');
    $a = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id]);
    task('Review', '2026-10-12', '2026-10-13', ['assignee_id' => $bo->id]);
    $loose = task('Unassigned', '2026-10-08', '2026-10-09');

    $state = rowsGantt()->getState([], '2026-10-01', '2026-10-31', exact: true);
    $bars = collect($state['bars'])->keyBy('id');

    expect($bars[(string) $a->id]['row'])->toBe((string) $bo->id)
        ->and($bars[(string) $loose->id]['row'])->toBe('')
        // in order of first appearance, a row met on a bar has no group of its own
        ->and($state['rows'])->toBe([
            ['key' => (string) $bo->id, 'label' => 'Bo', 'color' => null, 'group' => null, 'listed' => false],
            ['key' => (string) $cy->id, 'label' => 'Cy', 'color' => null, 'group' => null, 'listed' => false],
        ])
        ->and(gantt()->getState()['rows'])->toBe([])
        ->and(gantt()->getBars()[0])->not->toHaveKey('row');
});

it('lists the rows of rows() first, in their order and in their group, empty ones included', function () {
    $acme = project('Acme');
    $bo = person('Bo');
    $cy = person('Cy');
    $di = person('Di');
    task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $di->id, 'project_id' => $acme->id]);

    $rows = rowsGantt()
        ->rows(fn () => [
            ...Row::fromModels(User::query()->whereIn('id', [$cy->id, $bo->id])->orderBy('name', 'desc')->get(), group: fn (User $user) => $user->is($bo) ? $acme->id : null),
            Row::make('night')->label('Night shift')->color('indigo'),
        ])
        ->getState([], '2026-10-01', '2026-10-31', exact: true)['rows'];

    expect($rows)->toBe([
        ['key' => (string) $cy->id, 'label' => 'Cy', 'color' => null, 'group' => null, 'listed' => true],
        ['key' => (string) $bo->id, 'label' => 'Bo', 'color' => null, 'group' => (string) $acme->id, 'listed' => true],
        ['key' => 'night', 'label' => 'Night shift', 'color' => 'indigo', 'group' => null, 'listed' => true],
        ['key' => (string) $di->id, 'label' => 'Di', 'color' => null, 'group' => null, 'listed' => false],
    ]);
});

it('keeps a listed row whatever the filters leave, and shows a met row only while a bar is in it', function () {
    $bo = person('Bo');
    $cy = person('Cy');
    task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id]);

    $gantt = rowsGantt()->rows([Row::make($bo->id)->label('Bo')])->searchable(['title']);

    expect(array_column($gantt->getState(search: 'Build')['rows'], 'label'))->toBe(['Bo', 'Cy'])
        ->and(array_column($gantt->getState(search: 'Design')['rows'], 'label'))->toBe(['Bo']);
});

it('rows by a column too, labelled with its value', function () {
    shift('Morning', '2026-10-14 08:00', '2026-10-14 12:00', ['room' => 'north']);

    $state = shifts()->groupBy(null)->rowBy('room')->getState();

    expect($state['bars'][0]['row'])->toBe('north')
        ->and($state['rows'])->toBe([['key' => 'north', 'label' => 'north', 'color' => null, 'group' => null, 'listed' => false]]);
});

it('labels a row with rowBy()\'s label, a dotted path or a closure', function () {
    $bo = person('Bo');
    task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);

    expect(rowsGantt()->rowBy('assignee', label: 'assignee.email')->getState()['rows'][0]['label'])->toBe('bo@example.test')
        ->and(rowsGantt()->rowBy('assignee', label: fn (Task $task) => 'Team '.$task->assignee->name)->getState()['rows'][0]['label'])->toBe('Team Bo');
});

it('loads the row\'s relation once, not once per bar', function () {
    $bo = person('Bo');
    foreach (range(1, 5) as $i) {
        task("Task {$i}", '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    }

    DB::enableQueryLog();
    gantt()->rowBy('assignee')->getBars();

    expect(collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/["`]users["`]/', $q['query']) === 1)->count())->toBe(1);
});

it('refuses rowBy() with compact(), with lineBy(), to the chart\'s own model, or through a relation that is no BelongsTo', function (Closure $define, string $message) {
    task('Design', '2026-10-05', '2026-10-07');

    expect(fn () => $define(gantt())->getState())->toThrow(LogicException::class, $message);
})->with([
    'compact' => [fn (Gantt $g) => $g->rowBy('assignee')->compact(), 'Use one of them'],
    'lineBy' => [fn (Gantt $g) => $g->rowBy('assignee')->lineBy('parent_id'), 'do not work on one chart yet'],
    'own model' => [fn (Gantt $g) => $g->rowBy('parent'), 'parent tasks as rows'],
    'many' => [fn (Gantt $g) => $g->rowBy('predecessors'), 'neither'],
]);

it('drops a listed row\'s group on a chart without groups, and never lists a row without a key', function () {
    $rows = rowsGantt()->groupBy(null)
        ->rows([Row::make('a')->group('x'), Row::make('')])
        ->getState([], '2026-10-01', '2026-10-31', exact: true)['rows'];

    expect($rows)->toBe([['key' => 'a', 'label' => 'A', 'color' => null, 'group' => null, 'listed' => true]]);
});

it('names the unassigned row, and tells the browser when it stays in every group', function () {
    $config = fn (Closure $define) => (function () use ($define) {
        $define($this->getGantt());

        return $this->getGanttConfig(withState: true);
    })->call(Livewire::test(RowGantt::class)->instance());

    expect($config(fn (Gantt $gantt) => $gantt))->toMatchArray(['unassignedLabel' => 'Unassigned', 'unassignedRow' => false])
        ->and($config(fn (Gantt $gantt) => $gantt->unassignedRow('No one yet')))->toMatchArray(['unassignedLabel' => 'No one yet', 'unassignedRow' => true])
        ->and($config(fn (Gantt $gantt) => $gantt->unassignedRow(fn () => 'Backlog', always: false)))->toMatchArray(['unassignedLabel' => 'Backlog', 'unassignedRow' => false])
        ->and(Livewire::test(TaskGantt::class)->instance()->getGanttConfig(withState: true))->toMatchArray(['unassignedLabel' => null, 'unassignedRow' => false]);
});

it('tells the browser the rows lay out the chart, and whether a drag may change the group', function () {
    expect(Livewire::test(RowGantt::class)->instance()->getGanttConfig(withState: true))
        ->toMatchArray(['rowLayout' => true, 'preventRowOverlap' => false, 'groupMoves' => true, 'rows' => []])
        ->and(Livewire::test(TaskGantt::class)->instance()->getGanttConfig(withState: true))
        ->toMatchArray(['rowLayout' => false, 'preventRowOverlap' => false]);

    expect(shifts()->groupBy(null)->rowBy('room')->movesBetweenGroups())->toBeFalse();
});

/* ------------------------------------------------------------------ moving to another row */

it('moves a bar to another row: the foreign key with the dates, in one save, told to the closures and the event', function () {
    Event::fake([BarMoved::class]);
    $bo = person('Bo');
    $cy = person('Cy');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id]);
    $seen = [];

    $bar = rowsGantt()
        ->canMove(function (...$args) use (&$seen) {
            $seen = array_slice($args, 5);

            return true;
        })
        ->move((string) $task->id, '2026-10-06 00:00', '2026-10-09 00:00', row: (string) $cy->id);

    expect($task->fresh()->assignee_id)->toBe($cy->id)
        ->and($task->fresh()->starts_on->toDateString())->toBe('2026-10-06')
        ->and($bar['row'])->toBe((string) $cy->id)
        ->and($seen)->toBe([null, null, (string) $cy->id, (string) $bo->id]);

    Event::assertDispatched(BarMoved::class, fn (BarMoved $e) => $e->isRowChange() && $e->previousRow === (string) $bo->id && $e->row === (string) $cy->id && ! $e->isRegroup());
});

it('moves a bar to a row of another group, writing both', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $bo = person('Bo');
    $cy = person('Cy');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id, 'project_id' => $acme->id]);
    task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id, 'project_id' => $beta->id]);

    rowsGantt()->move((string) $task->id, '2026-10-05 00:00', null, (string) $beta->id, (string) $cy->id);

    expect($task->fresh())->project_id->toBe($beta->id)->assignee_id->toBe($cy->id);
});

it('moves a bar to a listed row that is still empty, and off its row while the chart shows the unassigned row', function () {
    $bo = person('Bo');
    $cy = person('Cy');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);

    $gantt = rowsGantt()->rows([Row::make($cy->id)]);
    $gantt->move((string) $task->id, '2026-10-05', null, row: (string) $cy->id);

    expect($task->fresh()->assignee_id)->toBe($cy->id)
        // no bar without a row on the chart: there is no unassigned row to drop on
        ->and(rowRefusal(fn () => rowsGantt()->move((string) $task->id, '2026-10-05', null, row: '')))->toBe('That row is not on the chart.')
        // a new name alone does not keep it there
        ->and(rowRefusal(fn () => rowsGantt()->unassignedRow('Backlog', always: false)->move((string) $task->id, '2026-10-05', null, row: '')))->toBe('That row is not on the chart.');

    task('Loose', '2026-10-08', '2026-10-09');
    rowsGantt()->move((string) $task->id, '2026-10-05', null, row: '');

    expect($task->fresh()->assignee_id)->toBeNull();
});

it('takes a bar off its row whenever unassignedRow() keeps that row on the chart', function () {
    $bo = person('Bo');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);

    $bar = rowsGantt()->unassignedRow()->move((string) $task->id, '2026-10-05', null, row: '');

    expect($bar['row'])->toBe('')
        ->and($task->fresh()->assignee_id)->toBeNull()
        // still a move like any other: regroupable() decides
        ->and(rowRefusal(fn () => rowsGantt()->unassignedRow()->regroupable(false)->move((string) task('Fixed', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id])->id, '2026-10-05', null, row: '')))
        ->toBe('This bar cannot be moved to another row.');
});

it('refuses a row the chart does not show, a related record that exists included, and a key of another shape', function () {
    $bo = person('Bo');
    $cy = person('Cy');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);

    expect(rowRefusal(fn () => rowsGantt()->move((string) $task->id, '2026-10-05', null, row: (string) $cy->id)))->toBe('That row is not on the chart.')
        ->and(rowRefusal(fn () => rowsGantt()->move((string) $task->id, '2026-10-05', null, row: 'abc')))->toBe('That row is not on the chart.')
        ->and($task->fresh()->assignee_id)->toBe($bo->id);
});

it('refuses a row its group does not show: one met in another group, or listed for another group', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $bo = person('Bo');
    $cy = person('Cy');
    $di = person('Di');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id, 'project_id' => $acme->id]);
    $build = task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id, 'project_id' => $beta->id]);

    $gantt = fn () => rowsGantt()->rows([Row::make($di->id)->group($beta->id)]);

    // Cy's row is met in Beta only, Di's is listed for Beta: neither is a row of Acme
    expect(rowRefusal(fn () => $gantt()->move((string) $task->id, '2026-10-05', null, row: (string) $cy->id)))->toBe('That row is not on the chart.')
        ->and(rowRefusal(fn () => $gantt()->move((string) $task->id, '2026-10-05', null, row: (string) $di->id)))->toBe('That row is not on the chart.')
        // a group change alone would carry Cy's row to Acme, where it does not show either
        ->and(rowRefusal(fn () => $gantt()->move((string) $build->id, '2026-10-06', null, (string) $acme->id)))->toBe('That row is not on the chart.')
        // nor is there an unassigned row in Beta while every bar there has a row
        ->and(rowRefusal(fn () => $gantt()->move((string) $build->id, '2026-10-06', null, row: '')))->toBe('That row is not on the chart.')
        ->and($task->fresh()->assignee_id)->toBe($bo->id)
        ->and($build->fresh()->project_id)->toBe($beta->id);

    // with the group, the bar lands on the row where it shows
    $gantt()->move((string) $task->id, '2026-10-05', null, (string) $beta->id, (string) $di->id);

    expect($task->fresh())->project_id->toBe($beta->id)->assignee_id->toBe($di->id);
});

it('creates in a row only under a group that shows it', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $bo = person('Bo');
    $cy = person('Cy');
    task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id, 'project_id' => $acme->id]);
    task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id, 'project_id' => $beta->id]);

    $gantt = rowsGantt()->createAction(CreateAction::make());

    expect($gantt->canCreateInRow((string) $cy->id, (string) $beta->id))->toBeTrue()
        ->and($gantt->canCreateInRow((string) $cy->id, (string) $acme->id))->toBeFalse()
        ->and($gantt->canCreateInRow((string) $cy->id))->toBeFalse()
        // a row listed without a group shows in every group
        ->and(rowsGantt()->createAction(CreateAction::make())->rows([Row::make($cy->id)])->canCreateInRow((string) $cy->id, (string) $acme->id))->toBeTrue();
});

it('keeps a bar on its row when regroupable() says no, the chart is read-only, or the bar is past the lock', function () {
    $bo = person('Bo');
    $cy = person('Cy');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id]);

    expect(rowRefusal(fn () => rowsGantt()->regroupable(false)->move((string) $task->id, '2026-10-05', null, row: (string) $cy->id)))->toBe('This bar cannot be moved to another row.')
        ->and(rowRefusal(fn () => rowsGantt()->readOnly()->move((string) $task->id, '2026-10-05', null, row: (string) $cy->id)))->toBe('This chart is read-only.')
        ->and(rowRefusal(fn () => rowsGantt()->lockedBefore(CarbonImmutable::parse('2026-10-10'))->move((string) $task->id, '2026-10-05', null, row: (string) $cy->id)))->toStartWith('Nothing can be scheduled before')
        ->and($task->fresh()->assignee_id)->toBe($bo->id)
        // its dates still move when regroupable() says no
        ->and(rowsGantt()->regroupable(false)->move((string) $task->id, '2026-10-06')['start'])->toBe('2026-10-06 00:00');
});

it('refuses another group when the group cannot be written, even on a chart with rows', function () {
    $task = shift('Morning', '2026-10-14 08:00', '2026-10-14 12:00', ['room' => 'north']);
    shift('Evening', '2026-10-14 16:00', '2026-10-14 20:00', ['room' => 'south']);

    $gantt = shifts()->groupBy(null)->rowBy('room');

    expect(rowRefusal(fn () => $gantt->move((string) $task->id, '2026-10-14 08:00', null, 'elsewhere')))->toBe('This bar cannot be moved to another row.');

    $gantt->move((string) $task->id, '2026-10-14 13:00', null, row: 'south');

    expect($task->fresh()->room)->toBe('south')
        ->and($task->fresh()->starts_at->format('H:i'))->toBe('13:00');
});

it('takes the row through Livewire, and refuses one of another shape', function () {
    $bo = person('Bo');
    $cy = person('Cy');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id]);

    Livewire::test(RowGantt::class)
        ->call('ganttMove', (string) $task->id, '2026-10-05 00:00', null, ['1'], (string) $cy->id)
        ->assertReturned(['ok' => false, 'message' => 'The change did not go through.'])
        ->call('ganttMove', (string) $task->id, '2026-10-05 00:00', null, null, [(string) $cy->id])
        ->assertReturned(['ok' => false, 'message' => 'The change did not go through.'])
        ->call('ganttMove', (string) $task->id, '2026-10-05 00:00', null, null, (string) $cy->id);

    expect($task->fresh()->assignee_id)->toBe($cy->id);
});

/* ------------------------------------------------------------------ preventRowOverlap() */

it('refuses a move or a resize onto another bar of the row with preventRowOverlap(), in any group', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $bo = person('Bo');
    $cy = person('Cy');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id, 'project_id' => $acme->id]);
    task('Busy', '2026-10-10', '2026-10-12', ['assignee_id' => $bo->id, 'project_id' => $beta->id]);
    task('Build', '2026-10-06', '2026-10-09', ['assignee_id' => $cy->id, 'project_id' => $acme->id]);

    $gantt = rowsGantt()->preventRowOverlap();

    expect(rowRefusal(fn () => $gantt->move((string) $task->id, '2026-10-09 00:00')))->toBe(ROW_OVERLAPS)
        ->and(rowRefusal(fn () => $gantt->move((string) $task->id, '2026-10-05 00:00', '2026-10-11 00:00')))->toBe(ROW_OVERLAPS)
        ->and(rowRefusal(fn () => $gantt->move((string) $task->id, '2026-10-05 00:00', null, row: (string) $cy->id)))->toBe(ROW_OVERLAPS)
        // a bar that ends the day before the next starts touches it, nothing more
        ->and($gantt->move((string) $task->id, '2026-10-07 00:00')['start'])->toBe('2026-10-07 00:00')
        // without the rule they stack
        ->and(rowsGantt()->move((string) $task->id, '2026-10-10 00:00')['start'])->toBe('2026-10-10 00:00');
});

it('never checks a bar without a row for overlaps', function () {
    $task = task('Loose', '2026-10-05', '2026-10-07');
    task('Other loose', '2026-10-08', '2026-10-09');

    expect(rowsGantt()->preventRowOverlap()->move((string) $task->id, '2026-10-08 00:00')['start'])->toBe('2026-10-08 00:00')
        ->and(rowsGantt()->overlapsOnRow(new Task(['starts_on' => '2026-10-08', 'ends_on' => '2026-10-09'])))->toBeFalse();
});

it('says whether a record would overlap a bar of its row, for an app\'s own forms', function () {
    $bo = person('Bo');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);

    expect(rowsGantt()->overlapsOnRow(new Task(['assignee_id' => $bo->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-09'])))->toBeTrue()
        ->and(rowsGantt()->overlapsOnRow(new Task(['assignee_id' => $bo->id, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-09'])))->toBeFalse()
        ->and(rowsGantt()->overlapsOnRow($task))->toBeFalse();
});

it('compares datetimes to the minute on a row', function () {
    shift('Morning', '2026-10-14 08:00', '2026-10-14 12:00', ['room' => 'north']);
    $late = shift('Late', '2026-10-14 13:00', '2026-10-14 15:00', ['room' => 'north']);

    $gantt = shifts()->groupBy(null)->rowBy('room')->preventRowOverlap();

    expect(rowRefusal(fn () => $gantt->move((string) $late->id, '2026-10-14 11:00')))->toBe(ROW_OVERLAPS)
        ->and($gantt->move((string) $late->id, '2026-10-14 12:00')['start'])->toBe('2026-10-14 12:00');
});

/* ------------------------------------------------------------------ creating in a row */

it('creates in the row that was clicked: the foreign key along with the group', function () {
    $acme = project('Acme');
    $bo = person('Bo');
    task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id, 'project_id' => $acme->id]);

    Livewire::test(RowGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttEnd' => '2026-10-14 00:00', 'ganttGroup' => (string) $acme->id, 'ganttRow' => (string) $bo->id])
        ->set('mountedActions.0.data.title', 'Review')
        ->callMountedAction()
        ->assertHasNoErrors()
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttGroup' => (string) $acme->id, 'ganttRow' => ''])
        ->set('mountedActions.0.data.title', 'Loose')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect(Task::firstWhere('title', 'Review'))->assignee_id->toBe($bo->id)->project_id->toBe($acme->id)
        ->and(Task::firstWhere('title', 'Loose')->assignee_id)->toBeNull();
});

it('creates in a listed row that has no bar yet, never in one the chart does not show', function () {
    $bo = person('Bo');
    $cy = person('Cy');
    RowGantt::$rows = fn () => [Row::make($bo->id)->label('Bo')];

    Livewire::test(RowGantt::class)
        ->assertActionVisible('create', ['ganttStart' => '2026-10-12 00:00', 'ganttRow' => (string) $bo->id])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttRow' => (string) $cy->id])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttRow' => 'abc'])
        ->call('mountAction', 'create', ['ganttStart' => '2026-10-12 00:00', 'ganttRow' => (string) $cy->id])
        ->set('mountedActions.0.data.title', 'Parked')
        ->call('callMountedAction');

    // a chart without rows takes no row at all
    Livewire::test(TaskGantt::class)
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttRow' => (string) $bo->id]);

    expect(Task::where('title', 'Parked')->exists())->toBeFalse();
});

it('lets creatableInRow() decide which rows a click may create in', function () {
    $bo = person('Bo');
    $cy = person('Cy');
    RowGantt::$creatableInRow = fn (string $row, ?string $group) => $row === (string) $cy->id && $group === null;

    Livewire::test(RowGantt::class)
        ->assertActionVisible('create', ['ganttStart' => '2026-10-12 00:00', 'ganttRow' => (string) $cy->id])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttRow' => (string) $bo->id]);
});

it('preselects the row in a create form that has the field', function () {
    $bo = person('Bo');
    task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);

    $component = new class extends RowGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->createAction(CreateAction::make()->schema([
                TextInput::make('title')->required(),
                Select::make('assignee_id')->options(fn () => User::query()->pluck('name', 'id')->all()),
            ]));
        }
    };

    Livewire::test($component::class)
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttRow' => (string) $bo->id])
        ->assertSet('mountedActions.0.data.assignee_id', (string) $bo->id);
});

it('keeps the create and edit forms open while the bar overlaps another of its row, with preventRowOverlap()', function () {
    RowGantt::$preventOverlap = true;
    $bo = person('Bo');
    $task = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    $other = task('Build', '2026-10-12', '2026-10-13', ['assignee_id' => $bo->id]);

    Livewire::test(RowGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-06 00:00', 'ganttEnd' => '2026-10-08 00:00', 'ganttRow' => (string) $bo->id])
        ->set('mountedActions.0.data.title', 'Clash')
        ->callMountedAction()
        ->assertNotified(ROW_OVERLAPS)
        ->mountAction('edit', ['ganttRecord' => (string) $other->id])
        ->set('mountedActions.0.data.starts_on', '2026-10-07')
        ->callMountedAction()
        ->assertNotified(ROW_OVERLAPS);

    expect(Task::where('title', 'Clash')->exists())->toBeFalse()
        ->and($other->fresh()->starts_on->toDateString())->toBe('2026-10-12')
        ->and($task->fresh()->starts_on->toDateString())->toBe('2026-10-05');
});

it('filters a chart with rows like any other', function () {
    $acme = project('Acme');
    $bo = person('Bo');
    task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id, 'project_id' => $acme->id]);
    task('Other', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);

    $state = rowsGantt()->filters([Filter::select('project_id')->options([$acme->id => 'Acme'])])->getState(['project_id' => (string) $acme->id]);

    expect(array_column($state['bars'], 'label'))->toBe(['Design'])
        ->and(array_column($state['rows'], 'label'))->toBe(['Bo']);
});
