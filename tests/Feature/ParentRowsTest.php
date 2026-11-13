<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Packstub\Gantt\Bar;
use Packstub\Gantt\Enums\ParentSpan;
use Packstub\Gantt\Events\BarMoved;
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
    TaskGantt::$bar = null;
    ParentGantt::$span = ParentSpan::Own;
    ParentGantt::$together = true;
    ParentGantt::$inside = false;
    ParentGantt::$write = false;
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

    $state = october(parentsGantt()->parentSpan(ParentSpan::None));

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
        // the parent matches, its children do not: its row, with its own span alone
        ->and(array_column($search['bars'], 'id'))->toBe([(string) $launch->id])
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

it('fits the chart to the bars, and to a parent\'s own dates only while they are drawn', function () {
    $launch = task('Launch', '2026-09-01', '2026-12-20');
    child($launch, 'Design', '2026-10-05', '2026-10-07');

    expect(parentsGantt()->parentSpan(ParentSpan::None)->getSpan())->toBe(['start' => '2026-10-05', 'end' => '2026-10-07'])
        ->and(parentsGantt()->parentSpan(ParentSpan::FromChildren)->getSpan())->toBe(['start' => '2026-10-05', 'end' => '2026-10-07'])
        // its own dates are on the chart when they are what the parent draws
        ->and(parentsGantt()->getSpan())->toBe(['start' => '2026-09-01', 'end' => '2026-12-20']);
});

it('has no unassigned row: a task without a parent is a row', function () {
    task('Launch');

    $config = Livewire::test(ParentGantt::class)->instance()->getGanttConfig(withState: true);

    expect($config['rowLayout'])->toBeTrue()
        ->and($config['unassignedRow'])->toBeFalse()
        ->and(array_column($config['rows'], 'label'))->toBe(['Launch'])
        ->and(parentsGantt()->unassignedRow('No parent')->showsUnassignedRow())->toBeFalse();
});

it('still refuses rowBy() with compact(), or with a lineBy() on the parent\'s own column', function () {
    expect(fn () => parentsGantt()->compact()->getState())->toThrow(LogicException::class, 'Use one of them')
        ->and(fn () => parentsGantt()->lineBy('parent_id')->getState())->toThrow(LogicException::class, 'read the same column');
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

/* ------------------------------------------------------------------ the parent's span */

it('draws a parent\'s own dates as its span: a bar on its own row, never carried to another', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    task('Ideas');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    $state = october(parentsGantt());
    $bars = collect($state['bars'])->keyBy('id');

    expect(array_column($state['bars'], 'id'))->toBe([(string) $launch->id, (string) $design->id])
        ->and($bars[(string) $launch->id])->toMatchArray(['row' => (string) $launch->id, 'parent' => true, 'start' => '2026-10-05 00:00', 'end' => '2026-10-21 00:00', 'movable' => true, 'resizable' => true, 'regroupable' => false])
        ->and($bars[(string) $launch->id])->not->toHaveKey('computed')
        ->and($bars[(string) $design->id])->not->toHaveKey('parent')
        // a parent without dates has no span, only its row
        ->and(array_column($state['rows'], 'label'))->toBe(['Launch', 'Ideas'])
        ->and($state['total'])->toBe(2);
});

it('computes the span from the children with ParentSpan::FromChildren, for a parent without dates too', function () {
    $launch = task('Launch', '2026-01-01', '2026-01-02');
    $ideas = task('Ideas');
    task('Empty');
    child($launch, 'Design', '2026-10-05', '2026-10-07');
    child($launch, 'Build', '2026-10-08', '2026-10-12', ['status' => 'done']);
    child($ideas, 'Sketch', '2026-10-20');
    // out of the window, still part of its parent's extent
    child($ideas, 'Later', '2026-11-10', '2026-11-12');

    $gantt = fn () => parentsGantt()->parentSpan(ParentSpan::FromChildren)->filters([Filter::select('status')->options(['todo' => 'To do', 'done' => 'Done'])]);
    $spans = fn (array $state) => collect($state['bars'])->filter(fn ($bar) => $bar['parent'] ?? false)->mapWithKeys(fn ($bar) => [$bar['label'] => [$bar['start'], $bar['end']]])->all();

    $state = october($gantt());
    $span = collect($state['bars'])->firstWhere('id', (string) $launch->id);

    expect($spans($state))->toBe(['Launch' => ['2026-10-05 00:00', '2026-10-13 00:00'], 'Ideas' => ['2026-10-20 00:00', '2026-11-13 00:00']])
        ->and($span)->toMatchArray(['row' => (string) $launch->id, 'parent' => true, 'computed' => true, 'movable' => true, 'resizable' => false, 'regroupable' => false, 'allDay' => true, 'milestone' => false])
        // the spans are not records: the count is the bars'
        ->and($state['total'])->toBe(3)
        // a filter never shrinks a parent
        ->and($spans(october($gantt(), ['status' => 'done']))['Launch'])->toBe(['2026-10-05 00:00', '2026-10-13 00:00'])
        // and a span outside the window is not sent
        ->and($spans($gantt()->getState([], '2026-12-01', '2026-12-31', exact: true)))->toBe([]);
});

it('computes every span in one query', function () {
    foreach (['Launch', 'Ideas', 'Later'] as $title) {
        child(task($title), 'Step', '2026-10-05', '2026-10-07');
    }

    DB::enableQueryLog();
    october(parentsGantt()->parentSpan(ParentSpan::FromChildren));

    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'gantt_children'))->count())->toBe(1);
});

it('reads a span of datetimes to the minute, and a lone milestone child as a point', function () {
    $night = shift('Night');
    $solo = shift('Solo');
    shift('First', '2026-10-14 09:00', '2026-10-14 11:00', ['parent_id' => $night->id]);
    shift('Second', '2026-10-14 13:30', '2026-10-14 15:00', ['parent_id' => $night->id]);
    shift('Ping', '2026-10-14 10:00', null, ['parent_id' => $solo->id]);

    $bars = collect(october(shifts()->groupBy(null)->rowBy('parent')->parentSpan(ParentSpan::FromChildren))['bars'])->keyBy('id');

    expect($bars[(string) $night->id])->toMatchArray(['start' => '2026-10-14 09:00', 'end' => '2026-10-14 15:00', 'allDay' => false, 'milestone' => false])
        ->and($bars[(string) $solo->id])->toMatchArray(['start' => '2026-10-14 10:00', 'end' => '2026-10-14 10:00', 'milestone' => true]);
});

it('draws no span with ParentSpan::None, and tells the browser which span it draws', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    child($launch, 'Design', '2026-10-05', '2026-10-07');

    $config = fn () => Livewire::test(ParentGantt::class)->instance()->getGanttConfig(withState: true);

    expect($config())->toMatchArray(['parentSpan' => 'own', 'moveChildren' => true])
        ->and(Livewire::test(TaskGantt::class)->instance()->getGanttConfig())->toMatchArray(['parentSpan' => null, 'moveChildren' => false]);

    ParentGantt::$span = ParentSpan::None;
    ParentGantt::$together = false;

    expect($config())->toMatchArray(['parentSpan' => 'none', 'moveChildren' => false])
        ->and(array_column($config()['bars'], 'label'))->toBe(['Design'])
        ->and(parentsGantt()->parentSpan('children')->getParentSpan())->toBe(ParentSpan::FromChildren)
        ->and(fn () => parentsGantt()->parentSpan('nope'))->toThrow(InvalidArgumentException::class, 'own, children or none');
});

it('puts the parent\'s actions on its row, as its bar would offer them', function () {
    $launch = task('Launch', null, null, ['progress' => 100]);
    $ideas = task('Ideas');
    TaskGantt::$bar = fn (Task $task) => $task->title === 'Ideas' ? Bar::make()->actions(['edit']) : null;

    $rows = collect(Livewire::test(ParentGantt::class)->instance()->getGanttConfig(withState: true)['rows'])->keyBy('key');

    // Delete is hidden for a finished task; Ideas offers Edit alone
    expect($rows[(string) $launch->id]['actions'])->toBe(['edit', 'bump'])
        ->and($rows[(string) $ideas->id]['actions'])->toBe(['edit'])
        // without bar actions a row says nothing about them
        ->and(october(parentsGantt())['rows'][0])->not->toHaveKey('actions');

    Livewire::test(ParentGantt::class)
        ->callAction('bump', arguments: ['ganttRecord' => (string) $launch->id]);

    expect($launch->fresh()->progress)->toBe(110);
});

/* ------------------------------------------------------------------ moving the span */

it('moves a parent and every child by the same amount, in one go', function () {
    Event::fake([BarMoved::class]);
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $build = child($launch, 'Build', '2026-10-08', '2026-10-12');
    // out of any window, a child all the same; one without dates stays as it is
    $later = child($launch, 'Later', '2026-12-01', '2026-12-02');
    $idea = child($launch, 'Idea');
    $other = child(task('Ideas'), 'Sketch', '2026-10-05', '2026-10-06');

    $bars = parentsGantt()->moveParent((string) $launch->id, '2026-10-07');

    expect(array_column($bars, 'start', 'id'))->toBe([
        (string) $launch->id => '2026-10-07 00:00',
        (string) $design->id => '2026-10-07 00:00',
        (string) $build->id => '2026-10-10 00:00',
        (string) $later->id => '2026-12-03 00:00',
    ])
        ->and($bars[0])->toMatchArray(['parent' => true, 'end' => '2026-10-23 00:00'])
        ->and($launch->fresh()->ends_on->toDateString())->toBe('2026-10-22')
        ->and($build->fresh()->ends_on->toDateString())->toBe('2026-10-14')
        ->and($idea->fresh()->starts_on)->toBeNull()
        ->and($other->fresh()->starts_on->toDateString())->toBe('2026-10-05');

    Event::assertDispatchedTimes(BarMoved::class, 4);
});

it('moves the children of a computed span, and leaves the parent\'s own dates alone', function () {
    $launch = task('Launch', '2026-01-01', '2026-01-02');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $build = child($launch, 'Build', '2026-10-08', '2026-10-12');

    $gantt = fn () => parentsGantt()->parentSpan(ParentSpan::FromChildren);
    $bars = $gantt()->moveParent((string) $launch->id, '2026-10-03');

    expect(array_map(fn ($bar) => [$bar['id'], $bar['start'], $bar['end']], $bars))->toBe([
        [(string) $launch->id, '2026-10-03 00:00', '2026-10-11 00:00'],
        [(string) $design->id, '2026-10-03 00:00', '2026-10-06 00:00'],
        [(string) $build->id, '2026-10-06 00:00', '2026-10-11 00:00'],
    ])
        ->and($bars[0])->toMatchArray(['computed' => true, 'parent' => true])
        ->and($launch->fresh()->starts_on->toDateString())->toBe('2026-01-01')
        // its own dates are not what the chart draws: they never move on their own
        ->and(parentRefusal(fn () => $gantt()->move((string) $launch->id, '2026-01-05')))->toBe('This bar cannot be moved.')
        ->and(parentRefusal(fn () => parentsGantt()->parentSpan(ParentSpan::None)->move((string) $launch->id, '2026-01-05')))->toBe('This bar cannot be moved.');
});

it('refuses the whole move when one child may not move, and names it', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    child($launch, 'Build', '2026-10-08', '2026-10-12');

    $gantt = fn () => parentsGantt()->movable(fn (Task $task) => $task->title !== 'Build');

    expect(parentRefusal(fn () => $gantt()->moveParent((string) $launch->id, '2026-10-07')))->toBe('Build, under this parent: This bar cannot be moved.')
        ->and($launch->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and($design->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        // the parent itself says why in its own words
        ->and(parentRefusal(fn () => parentsGantt()->movable(fn (Task $task) => $task->title !== 'Launch')->moveParent((string) $launch->id, '2026-10-07')))->toBe('This bar cannot be moved.')
        ->and(parentRefusal(fn () => parentsGantt()->parentSpan(ParentSpan::FromChildren)->movable(fn (Task $task) => $task->title !== 'Launch')->moveParent((string) $launch->id, '2026-10-07')))->toBe('This bar cannot be moved.');
});

it('keeps the children\'s places among each other with preventRowOverlap()', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $build = child($launch, 'Build', '2026-10-08', '2026-10-12');

    // Design lands where Build was: they move together, so nothing overlaps
    parentsGantt()->preventRowOverlap()->moveParent((string) $launch->id, '2026-10-08');

    expect($design->fresh()->starts_on->toDateString())->toBe('2026-10-08')
        ->and($build->fresh()->starts_on->toDateString())->toBe('2026-10-11');
});

it('moves a span with its children only for a parent, and only while the chart says so', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    $failed = 'The change did not go through.';

    expect(parentRefusal(fn () => parentsGantt()->moveParent((string) $design->id, '2026-10-07')))->toBe($failed)
        ->and(parentRefusal(fn () => parentsGantt()->moveChildrenWithParent(false)->moveParent((string) $launch->id, '2026-10-07')))->toBe($failed)
        ->and(parentRefusal(fn () => parentsGantt()->parentSpan(ParentSpan::None)->moveParent((string) $launch->id, '2026-10-07')))->toBe($failed)
        ->and(parentRefusal(fn () => gantt()->moveParent((string) $launch->id, '2026-10-07')))->toBe($failed)
        ->and(parentRefusal(fn () => parentsGantt()->readOnly()->moveParent((string) $launch->id, '2026-10-07')))->toBe('This chart is read-only.')
        ->and(parentRefusal(fn () => parentsGantt()->moveParent('999', '2026-10-07')))->toBe('This bar is no longer on the chart. Refresh to see the latest.')
        ->and($design->fresh()->starts_on->toDateString())->toBe('2026-10-05');
});

it('moves or resizes a parent alone through an ordinary move, and never to another row or group', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $launch = task('Launch', '2026-10-05', '2026-10-20', ['project_id' => $acme->id]);
    $ideas = task('Ideas', null, null, ['project_id' => $acme->id]);
    task('Other', null, null, ['project_id' => $beta->id]);
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    // a resize: the parent's dates only
    $bar = parentsGantt()->move((string) $launch->id, '2026-10-05', '2026-10-25');

    expect($bar)->toMatchArray(['end' => '2026-10-25 00:00', 'parent' => true])
        ->and($design->fresh()->ends_on->toDateString())->toBe('2026-10-07')
        ->and(parentRefusal(fn () => parentsGantt()->move((string) $launch->id, '2026-10-05', null, row: (string) $ideas->id)))->toBe('This bar cannot be moved to another row.')
        ->and(parentRefusal(fn () => parentsGantt()->move((string) $launch->id, '2026-10-05', null, (string) $beta->id)))->toBe('This bar cannot be moved to another row.')
        ->and($launch->fresh())->parent_id->toBeNull()->project_id->toBe($acme->id);
});

it('takes a span\'s move through Livewire, and tells the hook about every record that moved', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    $result = Livewire::test(ParentGantt::class)->instance()->ganttMoveParent((string) $launch->id, '2026-10-07');

    expect($result['ok'])->toBeTrue()
        ->and(array_column($result['bars'], 'id'))->toBe([(string) $launch->id, (string) $design->id])
        ->and(array_column(TaskGantt::$moved, 0))->toBe([$launch->id, $design->id])
        ->and(Livewire::test(ParentGantt::class)->instance()->ganttMoveParent(['x'], '2026-10-07'))->toBe(['ok' => false, 'message' => 'The change did not go through.'])
        ->and(Livewire::test(ParentGantt::class)->instance()->ganttMoveParent((string) $design->id, '2026-10-07')['ok'])->toBeFalse();
});

/* ------------------------------------------------------------------ keepInsideParent() */

const OUTSIDE_PARENT = 'This would leave the dates of its parent.';
const CHILDREN_OUTSIDE = 'A bar under this parent would be left outside its dates.';

it('keeps a child inside its parent\'s dates with keepInsideParent(): moved, resized or carried to another parent', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $short = task('Short', '2026-10-05', '2026-10-06');
    $ideas = task('Ideas');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    $gantt = fn () => parentsGantt()->keepInsideParent();

    expect(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-04')))->toBe(OUTSIDE_PARENT)
        ->and(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-19')))->toBe(OUTSIDE_PARENT)
        ->and(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-05', '2026-10-22')))->toBe(OUTSIDE_PARENT)
        // Short ends before Design does
        ->and(parentRefusal(fn () => $gantt()->move((string) $design->id, '2026-10-05', null, row: (string) $short->id)))->toBe(OUTSIDE_PARENT)
        // up to the parent's last day is inside
        ->and($gantt()->move((string) $design->id, '2026-10-18')['end'])->toBe('2026-10-21 00:00')
        // a parent without dates holds anything
        ->and($gantt()->move((string) $design->id, '2026-12-01', null, row: (string) $ideas->id)['row'])->toBe((string) $ideas->id)
        // and without the setting nothing is kept
        ->and(parentsGantt()->move((string) $design->id, '2026-10-01', null, row: (string) $launch->id)['start'])->toBe('2026-10-01 00:00');
});

it('never shrinks or moves a parent alone past one of its children, and moves them together freely', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $design = child($launch, 'Design', '2026-10-07', '2026-10-09');
    child($launch, 'Later', '2026-10-15', '2026-10-18');

    $gantt = fn () => parentsGantt()->keepInsideParent();

    expect(parentRefusal(fn () => $gantt()->move((string) $launch->id, '2026-10-08', '2026-10-21')))->toBe(CHILDREN_OUTSIDE)
        ->and(parentRefusal(fn () => $gantt()->move((string) $launch->id, '2026-10-05', '2026-10-18')))->toBe(CHILDREN_OUTSIDE)
        // moved alone, by a day too many
        ->and(parentRefusal(fn () => $gantt()->move((string) $launch->id, '2026-10-08')))->toBe(CHILDREN_OUTSIDE)
        // around its children is fine
        ->and($gantt()->move((string) $launch->id, '2026-10-07', '2026-10-19'))->toMatchArray(['start' => '2026-10-07 00:00', 'end' => '2026-10-19 00:00']);

    $gantt()->moveParent((string) $launch->id, '2026-11-02');

    expect($design->fresh()->starts_on->toDateString())->toBe('2026-11-02')
        ->and($launch->fresh()->starts_on->toDateString())->toBe('2026-11-02');
});

it('keeps children inside only where the parent draws its own dates, and tells the browser', function () {
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $outside = new Task(['title' => 'New', 'parent_id' => $launch->id, 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-22']);
    $inside = new Task(['title' => 'New', 'parent_id' => $launch->id, 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-20']);

    expect(parentsGantt()->keepInsideParent()->keepsInsideParent())->toBeTrue()
        ->and(parentsGantt()->keepInsideParent(fn () => false)->keepsInsideParent())->toBeFalse()
        ->and(parentsGantt()->keepInsideParent()->parentSpan(ParentSpan::FromChildren)->keepsInsideParent())->toBeFalse()
        ->and(gantt()->keepInsideParent()->keepsInsideParent())->toBeFalse()
        ->and(parentsGantt()->keepInsideParent()->parentSpan(ParentSpan::None)->move((string) $design->id, '2026-10-01')['start'])->toBe('2026-10-01 00:00')
        // for an app's own forms
        ->and(parentsGantt()->keepInsideParent()->outsideParent($outside))->toBe(OUTSIDE_PARENT)
        ->and(parentsGantt()->keepInsideParent()->outsideParent($inside))->toBeNull()
        ->and(parentsGantt()->outsideParent($outside))->toBeNull();

    ParentGantt::$inside = true;
    ParentGantt::$write = true;

    expect(Livewire::test(ParentGantt::class)->instance()->getGanttConfig())->toMatchArray(['keepInsideParent' => true, 'writeSpanToParent' => true])
        ->and(Livewire::test(TaskGantt::class)->instance()->getGanttConfig())->toMatchArray(['keepInsideParent' => false, 'writeSpanToParent' => false]);
});

it('keeps the create and edit forms open while a child would leave its parent\'s dates, or a parent its children', function () {
    ParentGantt::$inside = true;
    $launch = task('Launch', '2026-10-05', '2026-10-20');
    $design = child($launch, 'Design', '2026-10-07', '2026-10-09');

    Livewire::test(ParentGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-19 00:00', 'ganttEnd' => '2026-10-23 00:00', 'ganttRow' => (string) $launch->id])
        ->set('mountedActions.0.data.title', 'Too long')
        ->callMountedAction()
        ->assertNotified(OUTSIDE_PARENT)
        ->set('mountedActions.0.data.ends_on', '2026-10-20')
        ->callMountedAction()
        ->assertHasNoErrors();

    Livewire::test(ParentGantt::class)
        ->mountAction('edit', ['ganttRecord' => (string) $design->id])
        ->set('mountedActions.0.data.starts_on', '2026-10-01')
        ->callMountedAction()
        ->assertNotified(OUTSIDE_PARENT);

    Livewire::test(ParentGantt::class)
        ->mountAction('edit', ['ganttRecord' => (string) $launch->id])
        ->set('mountedActions.0.data.starts_on', '2026-10-08')
        ->callMountedAction()
        ->assertNotified(CHILDREN_OUTSIDE);

    expect(Task::where('title', 'Too long')->sole()->ends_on->toDateString())->toBe('2026-10-20')
        ->and($design->fresh()->starts_on->toDateString())->toBe('2026-10-07')
        ->and($launch->fresh()->starts_on->toDateString())->toBe('2026-10-05');
});

/* ------------------------------------------------------------------ writeSpanToParent() */

it('writes a parent\'s dates from its children when a child moves, with the move', function () {
    $launch = task('Launch', '2026-10-01', '2026-10-30');
    $ideas = task('Ideas');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $build = child($launch, 'Build', '2026-10-08', '2026-10-12');

    $gantt = fn () => parentsGantt()->writeSpanToParent();
    $dates = fn (Task $task) => [$task->fresh()->starts_on?->toDateString(), $task->fresh()->ends_on?->toDateString()];

    $gantt()->move((string) $build->id, '2026-10-10');
    expect($dates($launch))->toBe(['2026-10-05', '2026-10-14']);

    // carried to another parent: both are written, the one without dates gets its first
    $gantt()->move((string) $build->id, '2026-10-20', null, row: (string) $ideas->id);
    expect($dates($launch))->toBe(['2026-10-05', '2026-10-07'])
        ->and($dates($ideas))->toBe(['2026-10-20', '2026-10-24']);

    // a parent left without children keeps the dates it had
    $gantt()->move((string) $build->id, '2026-10-20', null, row: (string) $launch->id);
    expect($dates($ideas))->toBe(['2026-10-20', '2026-10-24'])
        ->and($dates($launch))->toBe(['2026-10-05', '2026-10-24'])
        // and nothing is written without the setting
        ->and(parentsGantt()->move((string) $design->id, '2026-09-01')['start'])->toBe('2026-09-01 00:00')
        ->and($dates($launch))->toBe(['2026-10-05', '2026-10-24']);
});

it('undoes the parent\'s dates with the move when afterMove() fails', function () {
    $launch = task('Launch', '2026-10-01', '2026-10-30');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');

    $gantt = parentsGantt()->writeSpanToParent()->afterMove(fn () => throw new MoveRejected('No.'));

    expect(parentRefusal(fn () => $gantt->move((string) $design->id, '2026-10-10')))->toBe('No.')
        ->and($launch->fresh()->starts_on->toDateString())->toBe('2026-10-01')
        ->and($design->fresh()->starts_on->toDateString())->toBe('2026-10-05');
});

it('writes datetimes as they are, and a lone milestone child as a point', function () {
    $night = shift('Night');
    $first = shift('First', '2026-10-14 09:00', '2026-10-14 11:00', ['parent_id' => $night->id]);
    shift('Second', '2026-10-14 13:30', '2026-10-14 15:00', ['parent_id' => $night->id]);

    shifts()->groupBy(null)->rowBy('parent')->writeSpanToParent()->move((string) $first->id, '2026-10-14 08:00');

    expect($night->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-14 08:00')
        ->and($night->fresh()->ends_at->format('Y-m-d H:i'))->toBe('2026-10-14 15:00');
});

it('writes the parent\'s dates after the chart\'s create, edit and delete actions', function () {
    ParentGantt::$write = true;
    $launch = task('Launch', '2026-10-01', '2026-10-30');
    $design = child($launch, 'Design', '2026-10-05', '2026-10-07');
    $dates = fn () => [$launch->fresh()->starts_on->toDateString(), $launch->fresh()->ends_on->toDateString()];

    Livewire::test(ParentGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttEnd' => '2026-10-15 00:00', 'ganttRow' => (string) $launch->id])
        ->set('mountedActions.0.data.title', 'Build')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($dates())->toBe(['2026-10-05', '2026-10-14']);

    Livewire::test(ParentGantt::class)
        ->mountAction('edit', ['ganttRecord' => (string) $design->id])
        ->set('mountedActions.0.data.starts_on', '2026-10-03')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($dates())->toBe(['2026-10-03', '2026-10-14']);

    Livewire::test(ParentGantt::class)->callAction('delete', arguments: ['ganttRecord' => (string) $design->id]);

    expect($dates())->toBe(['2026-10-12', '2026-10-14']);
});
