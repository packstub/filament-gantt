<?php

use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Packstub\Gantt\Events\BarMoved;
use Packstub\Gantt\Exceptions\MoveRejected;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\LineGantt;
use Packstub\Gantt\Tests\Fixtures\Project;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $this->actingAs(user());
    TaskGantt::$movable = null;
    TaskGantt::$readOnly = false;
    TaskGantt::$bar = null;
    TaskGantt::$moved = [];
    LineGantt::$preventOverlap = false;
    LineGantt::$alone = false;
    LineGantt::$canMove = null;
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The message a refused move gives. */
function refusal(Closure $move): string
{
    try {
        $move();
    } catch (MoveRejected $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('The move was not refused.');
}

const OVERLAPS = 'This would overlap another part of the same line.';

/* ------------------------------------------------------------------ reading */

it('puts a piece on its parent\'s line, and a record without a parent on a line of its own', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');
    $piece = task('Design, again', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);
    $alone = task('Alone', '2026-10-06', '2026-10-08');

    $bars = collect(gantt()->lineBy('parent_id')->getBars())->keyBy('id');

    expect($bars[(string) $root->id]['line'])->toBe((string) $root->id)
        ->and($bars[(string) $piece->id]['line'])->toBe((string) $root->id)
        ->and($bars[(string) $alone->id]['line'])->toBe((string) $alone->id)
        ->and(gantt()->getBars()[0])->not->toHaveKey('line');
});

it('sends every piece of a line that has several, those outside the window too, labelled after the line\'s own record', function () {
    $root = task('Design', '2026-10-12', '2026-10-13');
    $early = task('Design, first', '2026-10-05', '2026-10-06', ['parent_id' => $root->id]);
    $later = task('Design, last', '2026-12-01', '2026-12-02', ['parent_id' => $root->id]);
    task('Alone', '2026-10-06', '2026-10-08');

    $state = gantt()->lineBy('parent_id')->getState([], '2026-10-01', '2026-10-31', exact: true);
    $line = $state['lines'][0];

    expect(collect($state['bars'])->pluck('label')->sort()->values()->all())->toBe(['Alone', 'Design', 'Design, first'])
        ->and($state['lines'])->toHaveCount(1) // a record alone on its line needs nothing more
        ->and($line['key'])->toBe((string) $root->id)
        ->and($line['label'])->toBe('Design')
        // in the line's order: by start
        ->and(array_column($line['pieces'], 'id'))->toBe([(string) $early->id, (string) $root->id, (string) $later->id])
        ->and($line['pieces'][2])->toMatchArray(['start' => '2026-12-01 00:00', 'end' => '2026-12-03 00:00', 'allDay' => true, 'movable' => true, 'regroupable' => true]);
});

it('keeps a line\'s label when a filter hides its own record, and never reads one the chart\'s query does not show', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $root = task('Design', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);
    task('Later', '2026-10-12', '2026-10-13', ['parent_id' => $root->id, 'project_id' => $beta->id]);
    task('Latest', '2026-10-20', '2026-10-21', ['parent_id' => $root->id, 'project_id' => $beta->id]);

    $filtered = gantt()->lineBy('parent_id')
        ->filters([Filter::select('project_id')->options(fn () => Project::query()->pluck('name', 'id')->all())])
        ->getState(['project_id' => (string) $beta->id], '2026-10-01', '2026-10-31', true);

    expect(collect($filtered['bars'])->pluck('label')->all())->toBe(['Later', 'Latest'])
        ->and($filtered['lines'][0]['label'])->toBe('Design');

    $scoped = gantt()->lineBy('parent_id')
        ->query(fn () => Task::query()->where('title', '!=', 'Design'))
        ->getState([], '2026-10-01', '2026-10-31', true);

    expect($scoped['lines'][0]['label'])->toBe('Later')
        ->and(array_column($scoped['lines'][0]['pieces'], 'id'))->not->toContain((string) $root->id);
});

it('takes a BelongsTo relation to the same model as the line, and refuses any other relation', function () {
    $root = task('Design', '2026-10-05', '2026-10-06');
    task('Again', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);

    expect(gantt()->lineBy('parent')->getLineForeignKey())->toBe('parent_id')
        ->and(collect(gantt()->lineBy('parent')->getBars())->pluck('line')->unique()->values()->all())->toBe([(string) $root->id])
        ->and(fn () => gantt()->lineBy('project')->getBars())->toThrow(LogicException::class, 'BelongsTo relation to the same model');
});

it('draws a locked piece as one its line cannot move with', function () {
    $root = task('Design', '2026-10-05', '2026-10-07'); // before now: the past
    $next = task('Design, again', '2026-10-20', '2026-10-21', ['parent_id' => $root->id]);

    $pieces = gantt()->lineBy('parent_id')->lockedBefore(fn () => now())
        ->getState([], '2026-10-01', '2026-10-31', true)['lines'][0]['pieces'];

    expect(collect($pieces)->keyBy('id')->map(fn ($p) => [$p['movable'], $p['regroupable']])->all())
        ->toBe([(string) $root->id => [false, false], (string) $next->id => [true, true]]);
});

it('tells the browser how lines are drawn and dragged', function () {
    expect(Livewire::test(LineGantt::class)->instance()->getGanttConfig(withState: true))
        ->toMatchArray(['lineLayout' => true, 'movePiecesAlone' => false, 'preventLineOverlap' => false, 'lines' => []]);

    LineGantt::$alone = true;
    LineGantt::$preventOverlap = true;

    expect(Livewire::test(LineGantt::class)->instance()->getGanttConfig(withState: true))
        ->toMatchArray(['movePiecesAlone' => true, 'preventLineOverlap' => true])
        ->and(Livewire::test(TaskGantt::class)->instance()->getGanttConfig(withState: true))->toMatchArray(['lineLayout' => false, 'preventLineOverlap' => false])
        // the compact layout's rows are groups; its pieces still may not overlap
        ->and(gantt()->lineBy('parent_id')->compact()->showsLines())->toBeFalse()
        ->and(gantt()->lineBy('parent_id')->compact()->preventLineOverlap()->preventsLineOverlap())->toBeTrue()
        ->and(gantt()->preventLineOverlap()->preventsLineOverlap())->toBeFalse();
});

/* ------------------------------------------------------------------ moving a line */

it('moves every piece of a line by the same amount, those outside the window too', function () {
    Event::fake([BarMoved::class]);
    $root = task('Design', '2026-10-05', '2026-10-07');
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);
    $far = task('Design 3', '2026-12-01', '2026-12-02', ['parent_id' => $root->id]);
    $other = task('Other', '2026-10-05', '2026-10-07');

    $bars = collect(gantt()->lineBy('parent_id')->moveLine((string) $root->id, '2026-10-08 00:00'))->keyBy('id');

    expect($bars->pluck('id')->values()->all())->toBe([(string) $root->id, (string) $second->id, (string) $far->id])
        ->and($bars[(string) $root->id])->toMatchArray(['start' => '2026-10-08 00:00', 'end' => '2026-10-11 00:00'])
        ->and($bars[(string) $second->id])->toMatchArray(['start' => '2026-10-15 00:00', 'end' => '2026-10-17 00:00'])
        ->and($far->fresh()->starts_on->toDateString())->toBe('2026-12-04')
        ->and($far->fresh()->ends_on->toDateString())->toBe('2026-12-05')
        ->and($other->fresh()->starts_on->toDateString())->toBe('2026-10-05');

    Event::assertDispatchedTimes(BarMoved::class, 3);
});

it('answers ganttMoveLine with every piece, after afterMove() ran for each', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);

    Livewire::test(LineGantt::class)
        ->call('ganttMoveLine', (string) $root->id, '2026-10-06 00:00')
        ->assertReturned(fn ($result) => $result['ok'] === true && array_column($result['bars'], 'start') === ['2026-10-06 00:00', '2026-10-13 00:00']);

    expect(TaskGantt::$moved)->toBe([[$root->id, '2026-10-05', '2026-10-06'], [$second->id, '2026-10-12', '2026-10-13']]);
});

it('carries every piece of a line to another group', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $root = task('Design', '2026-10-05', '2026-10-07', ['project_id' => $acme->id]);
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id, 'project_id' => $acme->id]);
    task('Other', '2026-10-05', '2026-10-06', ['project_id' => $beta->id]); // Beta has a row

    $bars = gantt()->lineBy('parent_id')->moveLine((string) $second->id, '2026-10-12', (string) $beta->id);

    expect(array_column($bars, 'group'))->toBe([(string) $beta->id, (string) $beta->id])
        ->and($root->fresh()->project_id)->toBe($beta->id)
        ->and($second->fresh()->project_id)->toBe($beta->id)
        ->and($root->fresh()->starts_on->toDateString())->toBe('2026-10-05');

    // never into a row that is not on the chart
    expect(refusal(fn () => gantt()->lineBy('parent_id')->moveLine((string) $root->id, '2026-10-05', '424242')))->toBe('That row is not on the chart.');
});

it('refuses the whole line when one piece may not move, names that piece, and saves nothing', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);
    task('Design 3', '2026-12-01', '2026-12-02', ['parent_id' => $root->id]);

    $gantt = gantt()->lineBy('parent_id')->movable(fn (Task $task) => $task->title !== 'Design 3');

    expect(refusal(fn () => $gantt->moveLine((string) $root->id, '2026-10-08')))->toBe('Design 3, on the same line: This bar cannot be moved.')
        ->and($root->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and($second->fresh()->starts_on->toDateString())->toBe('2026-10-12');

    // the bar that was dragged speaks for itself
    $gantt = gantt()->lineBy('parent_id')->movable(fn (Task $task) => $task->title !== 'Design');

    expect(refusal(fn () => $gantt->moveLine((string) $root->id, '2026-10-08')))->toBe('This bar cannot be moved.');
});

it('asks canMove() about every piece of a line', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');
    task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);
    LineGantt::$canMove = fn (Task $task, CarbonImmutable $start) => $start->day === 15 ? 'Not on the 15th.' : true;

    Livewire::test(LineGantt::class)
        ->call('ganttMoveLine', (string) $root->id, '2026-10-08 00:00')
        ->assertReturned(['ok' => false, 'message' => 'Design 2, on the same line: Not on the 15th.']);

    expect($root->fresh()->starts_on->toDateString())->toBe('2026-10-05');
});

it('refuses to move a line that has a piece before the lock', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $root = task('Design', '2026-10-05', '2026-10-07', ['project_id' => $acme->id]);
    $next = task('Design 2', '2026-10-20', '2026-10-21', ['parent_id' => $root->id, 'project_id' => $acme->id]);
    task('Other', '2026-10-20', '2026-10-21', ['project_id' => $beta->id]);

    $gantt = gantt()->lineBy('parent_id')->lockedBefore(fn () => now());

    // carried to another group, any piece moves the line; Design lies before the lock
    expect(refusal(fn () => $gantt->moveLine((string) $next->id, '2026-10-20', (string) $beta->id)))->toBe('Design, on the same line: Nothing can be scheduled before Oct 14, 2026 10:00.')
        ->and($next->fresh()->project_id)->toBe($acme->id);
});

it('undoes every piece of the line when saving one of them fails', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');
    task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);

    $gantt = gantt()->lineBy('parent_id')->afterMove(function (Task $task) {
        if ($task->title === 'Design 2') {
            throw new RuntimeException('The mail server is down.');
        }
    });

    expect(fn () => $gantt->moveLine((string) $root->id, '2026-10-08'))->toThrow(RuntimeException::class)
        ->and($root->fresh()->starts_on->toDateString())->toBe('2026-10-05');

    Livewire::test(new class extends LineGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->afterMove(fn () => throw new RuntimeException('select * from secrets'));
        }
    })->call('ganttMoveLine', (string) $root->id, '2026-10-08')
        ->assertReturned(['ok' => false, 'message' => 'The change did not go through.']);
});

it('moves a line of shifts by whole hours, and a line of whole days by whole days', function () {
    $morning = shift('Morning', '2026-10-14 08:00', '2026-10-14 12:00');
    $afternoon = shift('Afternoon', '2026-10-14 14:00', '2026-10-14 17:00', ['parent_id' => $morning->id]);

    $bars = collect(shifts()->lineBy('parent_id')->moveLine((string) $morning->id, '2026-10-14 09:20'))->keyBy('id');

    expect($bars[(string) $morning->id]['start'])->toBe('2026-10-14 09:00')
        ->and($bars[(string) $afternoon->id])->toMatchArray(['start' => '2026-10-14 15:00', 'end' => '2026-10-14 18:00']);

    $root = task('Design', '2026-10-05', '2026-10-07');
    $next = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);

    gantt()->lineBy('parent_id')->snapTo('hour')->moveLine((string) $root->id, '2026-10-06 10:00');

    expect($root->fresh()->starts_on->toDateString())->toBe('2026-10-06')
        ->and($next->fresh()->starts_on->toDateString())->toBe('2026-10-13');
});

it('moves a line along its days only from its first piece, never with movePiecesAlone() or in the compact layout', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $root = task('Design', '2026-10-05', '2026-10-07', ['project_id' => $acme->id]);
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id, 'project_id' => $acme->id]);
    task('Other', '2026-10-05', '2026-10-06', ['project_id' => $beta->id]);

    expect(refusal(fn () => gantt()->lineBy('parent_id')->moveLine((string) $second->id, '2026-10-14')))->toBe('The change did not go through.')
        // naming the group it is already in is no change of group
        ->and(refusal(fn () => gantt()->lineBy('parent_id')->moveLine((string) $second->id, '2026-10-14', (string) $acme->id)))->toBe('The change did not go through.')
        ->and(refusal(fn () => gantt()->lineBy('parent_id')->movePiecesAlone()->moveLine((string) $root->id, '2026-10-08')))->toBe('The change did not go through.')
        ->and(refusal(fn () => gantt()->lineBy('parent_id')->compact()->moveLine((string) $root->id, '2026-10-08')))->toBe('The change did not go through.')
        ->and($root->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and($second->fresh()->starts_on->toDateString())->toBe('2026-10-12');

    // to another group, any piece carries the line, movePiecesAlone() or not
    gantt()->lineBy('parent_id')->movePiecesAlone()->moveLine((string) $second->id, '2026-10-12', (string) $beta->id);

    expect($root->fresh()->project_id)->toBe($beta->id);
});

it('moves no line on a chart without lines, on a read-only one, nor from a crafted request', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');

    expect(refusal(fn () => gantt()->moveLine((string) $root->id, '2026-10-08')))->toBe('The change did not go through.')
        ->and(refusal(fn () => gantt()->lineBy('parent_id')->readOnly()->moveLine((string) $root->id, '2026-10-08')))->toBe('This chart is read-only.')
        ->and(refusal(fn () => gantt()->lineBy('parent_id')->moveLine('424242', '2026-10-08')))->toBe('This bar is no longer on the chart. Refresh to see the latest.')
        ->and(refusal(fn () => gantt()->lineBy('parent_id')->moveLine((string) $root->id, '2026-02-30')))->toBe('The change did not go through.');

    Livewire::test(LineGantt::class)
        ->call('ganttMoveLine', ['1'], '2026-10-08')
        ->assertReturned(['ok' => false, 'message' => 'The change did not go through.'])
        ->call('ganttMoveLine', (string) $root->id, '2026-10-08', ['1'])
        ->assertReturned(['ok' => false, 'message' => 'The change did not go through.']);

    expect($root->fresh()->starts_on->toDateString())->toBe('2026-10-05');
});

/* ------------------------------------------------------------------ overlapping pieces */

it('refuses a move or a resize onto another piece of the line with preventLineOverlap(), and lets pieces touch', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);
    $gantt = gantt()->lineBy('parent_id')->preventLineOverlap();

    expect(refusal(fn () => $gantt->move((string) $second->id, '2026-10-07')))->toBe(OVERLAPS)
        ->and(refusal(fn () => $gantt->move((string) $root->id, '2026-10-05', '2026-10-13')))->toBe(OVERLAPS);

    // Design ends on the 7th: the next piece may start on the 8th
    $gantt->move((string) $second->id, '2026-10-08');

    expect($second->fresh()->starts_on->toDateString())->toBe('2026-10-08');

    // without the rule, overlapping pieces stack
    gantt()->lineBy('parent_id')->move((string) $second->id, '2026-10-06');

    expect($second->fresh()->starts_on->toDateString())->toBe('2026-10-06');
});

it('lets pieces with times touch, end to start', function () {
    $morning = shift('Morning', '2026-10-14 08:00', '2026-10-14 12:00');
    $afternoon = shift('Afternoon', '2026-10-14 14:00', '2026-10-14 17:00', ['parent_id' => $morning->id]);
    $gantt = shifts()->lineBy('parent_id')->preventLineOverlap();

    expect(refusal(fn () => $gantt->move((string) $afternoon->id, '2026-10-14 11:00')))->toBe(OVERLAPS);

    $gantt->move((string) $afternoon->id, '2026-10-14 12:00');

    expect($afternoon->fresh()->starts_at->format('H:i'))->toBe('12:00');
});

it('lets a whole line move, and a piece change rows only, whatever overlaps it already has', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    // overlapping already: written outside the chart
    $root = task('Design', '2026-10-05', '2026-10-08', ['project_id' => $acme->id]);
    $second = task('Design 2', '2026-10-07', '2026-10-09', ['parent_id' => $root->id, 'project_id' => $acme->id]);
    task('Other', '2026-10-05', '2026-10-06', ['project_id' => $beta->id]);
    $gantt = gantt()->lineBy('parent_id')->preventLineOverlap();

    $gantt->move((string) $second->id, '2026-10-07', null, (string) $beta->id);
    $gantt->moveLine((string) $root->id, '2026-10-06');

    expect($second->fresh()->project_id)->toBe($beta->id)
        ->and($root->fresh()->starts_on->toDateString())->toBe('2026-10-06')
        ->and($second->fresh()->starts_on->toDateString())->toBe('2026-10-08');
});

it('tells an app\'s own form whether a record would overlap its line', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');
    $gantt = gantt()->lineBy('parent_id');

    expect($gantt->overlapsOnLine(new Task(['parent_id' => $root->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-09'])))->toBeTrue()
        ->and($gantt->overlapsOnLine(new Task(['parent_id' => $root->id, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-09'])))->toBeFalse()
        ->and($gantt->overlapsOnLine(new Task(['starts_on' => '2026-10-06', 'ends_on' => '2026-10-09'])))->toBeFalse() // a line of its own
        ->and($gantt->overlapsOnLine($root))->toBeFalse()
        ->and(gantt()->overlapsOnLine($root))->toBeFalse();
});

/* ------------------------------------------------------------------ creating on a line */

it('adds a piece to the line whose row was clicked, and a task of its own from a group\'s row', function () {
    $acme = project('Acme');
    $root = task('Design', '2026-10-05', '2026-10-07', ['project_id' => $acme->id]);

    Livewire::test(LineGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttEnd' => '2026-10-14 00:00', 'ganttGroup' => (string) $acme->id, 'ganttLine' => (string) $root->id])
        ->set('mountedActions.0.data.title', 'Design, resumed')
        ->callMountedAction()
        ->assertHasNoErrors()
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttGroup' => (string) $acme->id])
        ->set('mountedActions.0.data.title', 'Review')
        ->callMountedAction()
        ->assertHasNoErrors();

    $piece = Task::firstWhere('title', 'Design, resumed');

    expect($piece->parent_id)->toBe($root->id)
        ->and($piece->project_id)->toBe($acme->id)
        ->and($piece->starts_on->toDateString())->toBe('2026-10-12')
        ->and($piece->ends_on->toDateString())->toBe('2026-10-13')
        ->and(Task::firstWhere('title', 'Review')->parent_id)->toBeNull();
});

it('adds a piece to a line from the row of any of its pieces, never to a piece itself', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');
    $piece = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);

    Livewire::test(LineGantt::class)
        ->assertActionVisible('create', ['ganttStart' => '2026-10-20 00:00', 'ganttLine' => (string) $root->id])
        // a piece is no line: a crafted key naming one never makes lines nest
        ->assertActionHidden('create', ['ganttStart' => '2026-10-20 00:00', 'ganttLine' => (string) $piece->id])
        ->call('mountAction', 'create', ['ganttStart' => '2026-10-20 00:00', 'ganttLine' => (string) $piece->id])
        ->set('mountedActions.0.data.title', 'Nested')
        ->call('callMountedAction');

    expect(Task::where('title', 'Nested')->exists())->toBeFalse();
});

it('never adds a piece to a line the chart does not show', function () {
    $hidden = task('Hidden', '2026-10-05', '2026-10-07');

    $component = new class extends LineGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->query(fn () => Task::query()->where('title', '!=', 'Hidden'));
        }
    };

    Livewire::test($component::class)
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttLine' => (string) $hidden->id])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttLine' => '424242'])
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttLine' => 'abc'])
        ->call('mountAction', 'create', ['ganttStart' => '2026-10-12 00:00', 'ganttLine' => (string) $hidden->id])
        ->set('mountedActions.0.data.title', 'Parked')
        ->call('callMountedAction');

    // a chart without lines takes no line at all
    Livewire::test(TaskGantt::class)
        ->assertActionHidden('create', ['ganttStart' => '2026-10-12 00:00', 'ganttLine' => (string) $hidden->id]);

    // an argument of another shape counts as absent: a task of its own
    Livewire::test(LineGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttLine' => [(string) $hidden->id]])
        ->set('mountedActions.0.data.title', 'Loose')
        ->callMountedAction();

    expect(Task::where('title', 'Parked')->exists())->toBeFalse()
        ->and(Task::firstWhere('title', 'Loose')->parent_id)->toBeNull();
});

it('preselects the line in a create form that has the field', function () {
    $root = task('Design', '2026-10-05', '2026-10-07');

    $component = new class extends LineGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->createAction(CreateAction::make()->schema([
                TextInput::make('title')->required(),
                Select::make('parent_id')->options(fn () => Task::query()->pluck('title', 'id')->all()),
            ]));
        }
    };

    Livewire::test($component::class)
        ->mountAction('create', ['ganttStart' => '2026-10-12 00:00', 'ganttLine' => (string) $root->id])
        ->assertSet('mountedActions.0.data.parent_id', (string) $root->id);
});

it('keeps the create form open while the new piece overlaps its line, with preventLineOverlap()', function () {
    LineGantt::$preventOverlap = true;
    $root = task('Design', '2026-10-05', '2026-10-07');

    Livewire::test(LineGantt::class)
        ->mountAction('create', ['ganttStart' => '2026-10-06 00:00', 'ganttEnd' => '2026-10-08 00:00', 'ganttLine' => (string) $root->id])
        ->set('mountedActions.0.data.title', 'Clash')
        ->callMountedAction()
        ->assertNotified(OVERLAPS)
        ->assertActionMounted('create')
        ->set('mountedActions.0.data.starts_on', '2026-10-08')
        ->set('mountedActions.0.data.ends_on', '2026-10-09')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect(Task::where('title', 'Clash')->count())->toBe(1)
        ->and(Task::firstWhere('title', 'Clash')->starts_on->toDateString())->toBe('2026-10-08');
});

it('refuses an edit that puts a piece onto another piece of its line, with preventLineOverlap()', function () {
    LineGantt::$preventOverlap = true;
    $root = task('Design', '2026-10-05', '2026-10-07');
    $piece = task('Design 2', '2026-10-12', '2026-10-13', ['parent_id' => $root->id]);

    Livewire::test(LineGantt::class)
        ->mountAction('edit', ['ganttRecord' => (string) $piece->id])
        ->set('mountedActions.0.data.starts_on', '2026-10-06')
        ->callMountedAction()
        ->assertNotified(OVERLAPS)
        ->set('mountedActions.0.data.starts_on', '2026-10-09')
        ->set('mountedActions.0.data.title', 'Design, sooner')
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($piece->fresh()->starts_on->toDateString())->toBe('2026-10-09')
        ->and($piece->fresh()->title)->toBe('Design, sooner');

    // a bar action without the dates in its data is not asked
    Livewire::test(LineGantt::class)->callAction('bump', arguments: ['ganttRecord' => (string) $piece->id]);

    expect($piece->fresh()->progress)->toBe(10);
});
