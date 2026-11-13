<?php

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Packstub\Gantt\Exceptions\MoveRejected;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;
use Packstub\Gantt\Tests\Fixtures\User;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $this->actingAs(user());
    TaskGantt::$movable = null;
    TaskGantt::$readOnly = false;
    TaskGantt::$moved = [];
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Someone tasks are assigned to: a row. */
function worker(string $name): User
{
    return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => 'secret']);
}

/** A chart with a row per assignee, whose split tasks (`parent_id`) keep their line on it. */
function rowLines(): Gantt
{
    return gantt()->groupBy(null)->query(fn () => Task::query()->with('assignee'))->rowBy('assignee')->lineBy('parent_id');
}

/** The message a refused move gives. */
function rowLineRefusal(Closure $move): string
{
    try {
        $move();
    } catch (MoveRejected $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('The move was not refused.');
}

it('puts the pieces of a line on their row, each with its line, and sends the line with every piece\'s row', function () {
    $bo = worker('Bo');
    $design = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['assignee_id' => $bo->id, 'parent_id' => $design->id]);
    $other = task('Other', '2026-10-06', '2026-10-08', ['assignee_id' => $bo->id]);

    $state = rowLines()->getState([], '2026-10-01', '2026-10-31', exact: true);
    $bars = collect($state['bars'])->keyBy(fn ($bar) => $bar['id']);

    expect($bars[(string) $second->id])->toMatchArray(['row' => (string) $bo->id, 'line' => (string) $design->id])
        ->and($bars[(string) $other->id]['line'])->toBe((string) $other->id)
        ->and(array_column($state['rows'], 'label'))->toBe(['Bo'])
        ->and($state['lines'])->toHaveCount(1)
        ->and(array_column($state['lines'][0]['pieces'], 'row'))->toBe([(string) $bo->id, (string) $bo->id])
        // a chart without rows says nothing of them
        ->and(gantt()->lineBy('parent_id')->getState([], '2026-10-01', '2026-10-31', exact: true)['lines'][0]['pieces'][0])->not->toHaveKey('row');

    $config = Livewire::test(TaskGantt::class)->instance()->getGanttConfig();

    expect($config['lineLayout'])->toBeFalse();
});

it('moves a line along its row from its first piece, the other bars of the row still in its way', function () {
    $bo = worker('Bo');
    $design = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    $second = task('Design 2', '2026-10-08', '2026-10-09', ['assignee_id' => $bo->id, 'parent_id' => $design->id]);
    task('Other', '2026-10-13', '2026-10-14', ['assignee_id' => $bo->id]);

    $gantt = fn () => rowLines()->preventRowOverlap();

    // Design lands where Design 2 was: they move together, nothing overlaps
    $bars = $gantt()->moveLine((string) $design->id, '2026-10-08');

    expect(array_column($bars, 'start', 'id'))->toBe([(string) $design->id => '2026-10-08 00:00', (string) $second->id => '2026-10-11 00:00'])
        // one more day and Design 2 would be on Other
        ->and(rowLineRefusal(fn () => $gantt()->moveLine((string) $design->id, '2026-10-09')))->toBe('Design 2, on the same line: This would overlap another bar in the same row.')
        ->and($design->fresh()->starts_on->toDateString())->toBe('2026-10-08');
});

it('carries a whole line to another row from any piece, and refuses a row the chart does not show', function () {
    $bo = worker('Bo');
    $cy = worker('Cy');
    $di = worker('Di');
    $design = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['assignee_id' => $bo->id, 'parent_id' => $design->id]);
    task('Build', '2026-10-20', '2026-10-21', ['assignee_id' => $cy->id]);

    $bars = rowLines()->moveLine((string) $second->id, '2026-10-12', null, (string) $cy->id);

    expect(array_column($bars, 'row', 'id'))->toBe([(string) $design->id => (string) $cy->id, (string) $second->id => (string) $cy->id])
        ->and($design->fresh()->assignee_id)->toBe($cy->id)
        ->and($design->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and(rowLineRefusal(fn () => rowLines()->moveLine((string) $second->id, '2026-10-12', null, (string) $di->id)))->toBe('Design, on the same line: That row is not on the chart.')
        // along its days, only the first piece carries the line
        ->and(rowLineRefusal(fn () => rowLines()->moveLine((string) $second->id, '2026-10-14', null, (string) $cy->id)))->toBe('The change did not go through.')
        ->and($second->fresh()->assignee_id)->toBe($cy->id);
});

it('refuses a line carried onto a taken stretch of another row, whichever piece is in the way', function () {
    $bo = worker('Bo');
    $cy = worker('Cy');
    $design = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['assignee_id' => $bo->id, 'parent_id' => $design->id]);
    task('Build', '2026-10-13', '2026-10-15', ['assignee_id' => $cy->id]);

    expect(rowLineRefusal(fn () => rowLines()->preventRowOverlap()->moveLine((string) $design->id, '2026-10-05', null, (string) $cy->id)))
        ->toBe('Design 2, on the same line: This would overlap another bar in the same row.')
        ->and($design->fresh()->assignee_id)->toBe($bo->id)
        ->and($second->fresh()->assignee_id)->toBe($bo->id);
});

it('keeps the pieces of a line apart on a row with preventLineOverlap(), and a piece moves alone', function () {
    $bo = worker('Bo');
    $design = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    $second = task('Design 2', '2026-10-12', '2026-10-13', ['assignee_id' => $bo->id, 'parent_id' => $design->id]);

    $gantt = fn () => rowLines()->preventLineOverlap();

    expect(rowLineRefusal(fn () => $gantt()->move((string) $second->id, '2026-10-07')))->toBe('This would overlap another part of the same line.')
        ->and($gantt()->move((string) $second->id, '2026-10-09')['start'])->toBe('2026-10-09 00:00')
        ->and($design->fresh()->starts_on->toDateString())->toBe('2026-10-05');
});

it('takes the row through ganttMoveLine, and refuses one of another shape', function () {
    $bo = worker('Bo');
    $cy = worker('Cy');
    $design = task('Design', '2026-10-05', '2026-10-07', ['assignee_id' => $bo->id]);
    task('Design 2', '2026-10-12', '2026-10-13', ['assignee_id' => $bo->id, 'parent_id' => $design->id]);
    task('Build', '2026-10-20', '2026-10-21', ['assignee_id' => $cy->id]);

    $component = new class extends TaskGantt
    {
        public function gantt(Gantt $gantt): Gantt
        {
            return parent::gantt($gantt)->groupBy(null)->query(fn () => Task::query()->with('assignee'))->rowBy('assignee')->lineBy('parent_id');
        }
    };

    $page = Livewire::test($component::class)->instance();

    expect($page->ganttMoveLine((string) $design->id, '2026-10-05', null, ['x']))->toBe(['ok' => false, 'message' => 'The change did not go through.'])
        ->and($page->ganttMoveLine((string) $design->id, '2026-10-05', null, (string) $cy->id)['ok'])->toBeTrue()
        ->and($design->fresh()->assignee_id)->toBe($cy->id)
        ->and($page->getGanttConfig())->toMatchArray(['lineLayout' => true, 'rowLayout' => true]);
});
