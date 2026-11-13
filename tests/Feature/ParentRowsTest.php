<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Packstub\Gantt\Exceptions\MoveRejected;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Row;
use Packstub\Gantt\Tests\Fixtures\ParentGantt;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $this->actingAs(user());
    TaskGantt::$movable = null;
    TaskGantt::$readOnly = false;
    TaskGantt::$moved = [];
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A chart of tasks grouped by project, whose rows are the tasks without a parent. */
function parentsGantt(): Gantt
{
    return gantt()->rowBy('parent');
}

/** A task under a parent. @param  array<string, mixed>  $attributes */
function child(Task $parent, string $title, ?string $starts = null, ?string $ends = null, array $attributes = []): Task
{
    return task($title, $starts, $ends, ['parent_id' => $parent->id, 'project_id' => $parent->project_id, ...$attributes]);
}

/** The state of October 2026. @return array<string, mixed> */
function october(Gantt $gantt, array $filters = [], string $search = ''): array
{
    return $gantt->getState($filters, '2026-10-01', '2026-10-31', exact: true, search: $search);
}

/** The message a refused move gives. */
function parentRefusal(Closure $move): string
{
    try {
        $move();
    } catch (MoveRejected $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('The move was not refused.');
}

const NO_SUCH_ROW = 'That row is not on the chart.';

/* ------------------------------------------------------------------ reading */

it('makes every task without a parent a row, and the tasks with one the bars on it', function () {
    $acme = project('Acme');
    $launch = task('Launch', '2026-10-05', '2026-10-20', ['project_id' => $acme->id]);
    $ideas = task('Ideas', null, null, ['project_id' => $acme->id]);
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $build = child($launch, 'Build', '2026-10-08', '2026-10-12');

    $state = october(parentsGantt());

    expect(array_column($state['bars'], 'id'))->toBe([(string) $design->id, (string) $build->id])
        ->and(array_column($state['bars'], 'row'))->toBe([(string) $launch->id, (string) $launch->id])
        // the parent with dates first, the one without after it; each in its own group, bars or not
        ->and($state['rows'])->toBe([
            ['key' => (string) $launch->id, 'label' => 'Launch', 'color' => null, 'group' => (string) $acme->id, 'listed' => true],
            ['key' => (string) $ideas->id, 'label' => 'Ideas', 'color' => null, 'group' => (string) $acme->id, 'listed' => true],
        ])
        ->and($state['total'])->toBe(2);
});

it('shows the group of a parent that has no bar yet, the ungrouped one included', function () {
    $acme = project('Acme');
    task('Launch', null, null, ['project_id' => $acme->id]);
    $loose = task('Loose');

    $state = october(parentsGantt());

    expect(array_column($state['groups'], 'label', 'key'))->toBe([(string) $acme->id => 'Acme', '' => 'Ungrouped'])
        ->and(array_column($state['groups'], 'count'))->toBe([0, 0])
        ->and(collect($state['rows'])->firstWhere('key', (string) $loose->id)['group'])->toBe('')
        // without groups a row names none
        ->and(array_column(october(parentsGantt()->groupBy(null))['rows'], 'group'))->toBe([null, null]);
});

it('names a parent\'s row with label(), or with rowBy()\'s label read from the parent, and dots it with color()', function () {
    $launch = task('Launch', null, null, ['status' => 'done']);

    expect(october(parentsGantt()->color('status'))['rows'][0])->toMatchArray(['label' => 'Launch', 'color' => 'done'])
        ->and(october(parentsGantt()->rowBy('parent', label: 'status'))['rows'][0]['label'])->toBe('done')
        ->and(october(parentsGantt()->rowBy('parent', label: fn (Task $parent) => '#'.$parent->id))['rows'][0]['label'])->toBe('#'.$launch->id);
});

it('keeps a parent\'s row only while the parent matches the filters and the search, or a bar on it does', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20', ['status' => 'doing']);
    $ideas = task('Ideas', null, null, ['status' => 'todo']);
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07', ['status' => 'todo']);
    child($ideas, 'Sketch', '2026-10-08', '2026-10-09', ['status' => 'done']);

    $gantt = fn () => parentsGantt()->filters([Filter::select('status')->options(['todo' => 'To do', 'doing' => 'Doing', 'done' => 'Done'])])->searchable(['title']);

    $todo = october($gantt(), ['status' => 'todo']);
    $search = october($gantt(), search: 'launch');

    // Ideas matches (its bar does not); Launch does not, and comes after it for its bar Design
    expect(array_column($todo['bars'], 'id'))->toBe([(string) $design->id])
        ->and(array_map(fn ($row) => [$row['label'], $row['listed']], $todo['rows']))->toBe([['Ideas', true], ['Launch', false]])
        // the parent matches, its children do not: an empty row
        ->and($search['bars'])->toBe([])
        ->and(array_column($search['rows'], 'label'))->toBe(['Launch']);
});

it('lists rows() before the parents, and keeps them whatever the filters leave', function () {
    $launch = task('Launch');
    $ideas = task('Ideas');

    $rows = october(parentsGantt()->rows([Row::make($ideas->id)->label('Pinned')])->searchable(['title']), search: 'launch')['rows'];

    expect(array_column($rows, 'label'))->toBe(['Pinned', 'Launch']);
});

it('gives a child of a child a row of its own, named after its parent', function () {
    $launch = task('Launch');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $logo = child($design, 'Logo', '2026-10-05', '2026-10-06');

    $state = october(parentsGantt());

    expect(array_column($state['bars'], 'row', 'id'))->toBe([(string) $design->id => (string) $launch->id, (string) $logo->id => (string) $design->id])
        ->and(array_map(fn ($row) => [$row['label'], $row['listed']], $state['rows']))->toBe([['Launch', true], ['Design', false]]);
});

it('names a row by its key when the chart\'s query does not show the parent', function () {
    $acme = project('Acme');
    $hidden = task('Secret');
    $design = child($hidden, 'Design', '2026-10-05', '2026-10-07', ['project_id' => $acme->id]);

    $state = october(parentsGantt()->query(fn () => Task::query()->with('project')->whereNotNull('project_id')));

    expect($state['rows'])->toBe([['key' => (string) $hidden->id, 'label' => (string) $hidden->id, 'color' => null, 'group' => null, 'listed' => false]])
        ->and(array_column($state['bars'], 'id'))->toBe([(string) $design->id]);
});

it('orders the parents as sortBy() says', function () {
    task('Beta', '2026-10-05', '2026-10-06');
    task('Alpha', '2026-10-09', '2026-10-10');

    expect(array_column(october(parentsGantt())['rows'], 'label'))->toBe(['Beta', 'Alpha'])
        ->and(array_column(october(parentsGantt()->sortBy('title'))['rows'], 'label'))->toBe(['Alpha', 'Beta']);
});

it('reads the parents in one query, and never loads a bar\'s parent one by one', function () {
    $launch = task('Launch');
    child($launch, 'Design', '2026-10-05', '2026-10-07');
    child($launch, 'Build', '2026-10-08', '2026-10-12');

    Model::preventLazyLoading();
    DB::enableQueryLog();

    try {
        october(parentsGantt());
    } finally {
        Model::preventLazyLoading(false);
    }

    expect(collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/from ["`]tasks["`]/', $q['query']) === 1)->count())->toBe(2);
});

it('fits the chart to the bars, not to a parent\'s own dates', function () {
    $launch = task('Launch', '2026-09-01', '2026-12-20');
    child($launch, 'Design', '2026-10-05', '2026-10-07');

    expect(parentsGantt()->getSpan())->toBe(['start' => '2026-10-05', 'end' => '2026-10-07']);
});

it('has no unassigned row: a task without a parent is a row', function () {
    task('Launch');

    $config = Livewire::test(ParentGantt::class)->instance()->getGanttConfig(withState: true);

    expect($config['rowLayout'])->toBeTrue()
        ->and($config['unassignedRow'])->toBeFalse()
        ->and(array_column($config['rows'], 'label'))->toBe(['Launch'])
        ->and(parentsGantt()->unassignedRow('No parent')->showsUnassignedRow())->toBeFalse();
});

it('still refuses rowBy() with compact(), with lineBy(), or through a relation that is no BelongsTo', function () {
    expect(fn () => parentsGantt()->compact()->getState())->toThrow(LogicException::class, 'Use one of them')
        ->and(fn () => parentsGantt()->lineBy('parent_id')->getState())->toThrow(LogicException::class, 'do not work on one chart yet');
});

/* ------------------------------------------------------------------ moving */

it('moves a bar to another parent\'s row, one that has no bar yet included', function () {
    $acme = project('Acme');
    $launch = task('Launch', null, null, ['project_id' => $acme->id]);
    $ideas = task('Ideas', null, null, ['project_id' => $acme->id]);
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    $bar = parentsGantt()->move((string) $design->id, '2026-10-06', null, row: (string) $ideas->id);

    expect($bar)->toMatchArray(['row' => (string) $ideas->id, 'start' => '2026-10-06 00:00'])
        ->and($design->fresh()->parent_id)->toBe($ideas->id);
});

it('moves a bar to a parent of another group only with that group', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $launch = task('Launch', null, null, ['project_id' => $acme->id]);
    $ideas = task('Ideas', null, null, ['project_id' => $beta->id]);
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    expect(parentRefusal(fn () => parentsGantt()->move((string) $design->id, '2026-10-05', null, row: (string) $ideas->id)))->toBe(NO_SUCH_ROW)
        // nor does its own row follow it to another group
        ->and(parentRefusal(fn () => parentsGantt()->move((string) $design->id, '2026-10-05', null, (string) $beta->id)))->toBe(NO_SUCH_ROW);

    parentsGantt()->move((string) $design->id, '2026-10-05', null, (string) $beta->id, (string) $ideas->id);

    expect($design->fresh())->parent_id->toBe($ideas->id)->project_id->toBe($beta->id);
});

it('refuses a row that is no parent on the chart: none at all, a record the query hides, a key of another shape', function () {
    $acme = project('Acme');
    $launch = task('Launch', null, null, ['project_id' => $acme->id]);
    $hidden = task('Secret');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    $gantt = fn () => parentsGantt()->query(fn () => Task::query()->with('project')->whereNotNull('project_id'))->unassignedRow('No parent');

    expect(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-05', null, row: '')))->toBe(NO_SUCH_ROW)
        ->and(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-05', null, row: (string) $hidden->id)))->toBe(NO_SUCH_ROW)
        ->and(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-05', null, row: 'abc')))->toBe(NO_SUCH_ROW)
        ->and(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-05', null, row: '999')))->toBe(NO_SUCH_ROW)
        ->and($design->fresh()->parent_id)->toBe($launch->id);
});

it('never puts a task under itself or under one of its own descendants', function () {
    $launch = task('Launch');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $logo = child($design, 'Logo', '2026-10-05', '2026-10-06');
    child($logo, 'Draft', '2026-10-05', '2026-10-05');

    $gantt = fn () => parentsGantt()->groupBy(null);

    expect(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-05', null, row: (string) $design->id)))->toBe(NO_SUCH_ROW)
        ->and(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-05', null, row: (string) $logo->id)))->toBe(NO_SUCH_ROW)
        ->and($design->fresh()->parent_id)->toBe($launch->id);

    // the other way is a move like any other
    $gantt()->move((string) $logo->id, '2026-10-05', null, row: (string) $launch->id);

    expect($logo->fresh()->parent_id)->toBe($launch->id);
});

it('keeps the bars of a parent apart with preventRowOverlap()', function () {
    $launch = task('Launch', '2026-10-01', '2026-10-30');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    child($launch, 'Build', '2026-10-08', '2026-10-12');

    $gantt = fn () => parentsGantt()->preventRowOverlap();

    // the parent's own dates never count
    expect(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-07')))->toBe('This would overlap another bar in the same row.')
        ->and($gantt()->move((string) $design->id, '2026-10-02')['start'])->toBe('2026-10-02 00:00');
});

/* ------------------------------------------------------------------ creating */

it('creates a child on the parent\'s row that was clicked, an empty one included', function () {
    $acme = project('Acme');
    $launch = task('Launch', null, null, ['project_id' => $acme->id]);

    Livewire::test(ParentGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttEnd' => '2026-10-14 00:00', 'ganttGroup' => (string) $acme->id, 'ganttRow' => (string) $launch->id])
        ->set('mountedActions.0.data.title', 'Design')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect(Task::firstWhere('title', 'Design'))->parent_id->toBe($launch->id)->project_id->toBe($acme->id);
});

it('creates only on a parent the chart shows, in the group it sits in', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $launch = task('Launch', null, null, ['project_id' => $acme->id]);
    task('Ideas', null, null, ['project_id' => $beta->id]);

    Livewire::test(ParentGantt::class)
        ->assertActionVisible('create', ['ganttStart' => '2026-10-12 00:00', 'ganttGroup' => (string) $acme->id, 'ganttRow' => (string) $launch->id])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttGroup' => (string) $beta->id, 'ganttRow' => (string) $launch->id])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttGroup' => (string) $acme->id, 'ganttRow' => '999'])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttGroup' => (string) $acme->id, 'ganttRow' => 'abc']);
});
