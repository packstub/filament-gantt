<?php

use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Packstub\Gantt\Bar;
use Packstub\Gantt\Enums\Scale;
use Packstub\Gantt\Enums\Unit;
use Packstub\Gantt\Events\BarMoved;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Group;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskStatus;
use Packstub\Gantt\Tests\Fixtures\UncastTask;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $this->actingAs(user());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Model::preventLazyLoading(false);
});

/* ------------------------------------------------------------------ the window's query */

it('sizes the window from the two date columns alone, whatever the query selects', function () {
    $acme = project('Acme');
    task('A', '2026-10-05', '2026-10-09', ['project_id' => $acme->id]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $window = gantt()->query(fn () => Task::query()->withCount('project')->addSelect(DB::raw("'x' as extra")))->getWindow();
    $span = collect($queries)->first(fn ($sql) => str_contains($sql, 'gantt_min'));

    // A strict MySQL or PostgreSQL refuses `select *, (…) as project_count, min(…)` without a GROUP BY.
    expect($window['start']->toDateString())->toBe('2026-09-28')
        ->and($span)->not->toContain('project_count')
        ->and($span)->not->toContain('extra')
        ->and($span)->toContain('gantt_span');
});

it('sizes the window on a connection with a table prefix', function () {
    $connection = DB::connection();
    $connection->setTablePrefix('x_');

    Schema::create('projects', fn (Blueprint $table) => $table->id() && $table->string('name') && $table->string('color')->nullable());
    Schema::create('tasks', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('project_id')->nullable();
        $table->string('title');
        $table->date('starts_on')->nullable();
        $table->date('ends_on')->nullable();
        $table->unsignedInteger('progress')->default(0);
    });

    try {
        task('A', '2026-10-05', '2026-10-09');

        expect(gantt()->getWindow()['start']->toDateString())->toBe('2026-09-28')
            ->and(array_column(gantt()->getState()['bars'], 'label'))->toBe(['A']);
    } finally {
        $connection->setTablePrefix('');
    }
});

it('opens a long history around today instead of on its oldest years', function () {
    task('Old', '2019-01-07', '2019-01-09');
    task('Now', '2026-10-12', '2026-10-16');

    $window = gantt()->getWindow();

    expect($window['start']->toDateString())->toBe('2026-10-07')
        ->and($window['end']->toDateString())->toBe('2026-11-03'); // the minimum, 28 days
});

it('ignores a window date before the year 1000, like a move', function () {
    task('A', '2026-10-05', '2026-10-09');

    expect(Filter::date('0999-12-31'))->toBeNull()
        ->and(gantt()->getWindow('0001-01-01', '0002-12-31')['start']->toDateString())->toBe('2026-09-28');
});

it('loads at most max_bars bars and tells how many the window holds', function () {
    config(['filament-gantt.max_bars' => 2]);
    task('A', '2026-10-05', '2026-10-06');
    task('B', '2026-10-07', '2026-10-08');
    task('C', '2026-10-09', '2026-10-10');

    $state = gantt()->getState();

    expect(array_column($state['bars'], 'label'))->toBe(['A', 'B'])
        ->and($state['total'])->toBe(3)
        ->and(gantt()->getBars())->toHaveCount(3);
});

it('loads the group relation once, never lazily', function () {
    Model::preventLazyLoading();
    $acme = project('Acme');
    task('A', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);
    task('B', '2026-10-07', '2026-10-08', ['project_id' => $acme->id]);

    $state = gantt()->query(fn () => Task::query())->groupBy('project')->getState();

    expect(array_column($state['groups'], 'label'))->toBe(['Acme']);
});

it('asks for a group with a qualified column, so a join cannot make it ambiguous', function () {
    task('Build', '2026-10-05', '2026-10-06');
    shift('Build', '2026-10-05 08:00:00', '2026-10-05 12:00:00');

    $gantt = gantt()
        ->query(fn () => Task::query()->select('tasks.*')->join('shifts', 'shifts.title', '=', 'tasks.title'))
        ->groupBy('title')
        ->createAction(CreateAction::make());

    expect($gantt->canCreateIn('Build'))->toBeTrue()
        ->and($gantt->canCreateIn('Other'))->toBeFalse();
});

it('refuses to group by a MorphTo relation', function () {
    task('A', '2026-10-05', '2026-10-06');

    expect(fn () => gantt()->groupBy('owner')->getState())->toThrow(LogicException::class, 'MorphTo');
});

/* ------------------------------------------------------------------ after a move */

it('answers a move with the bar as the chart\'s query reads it', function () {
    $acme = project('Acme');
    $task = task('A', '2026-10-05', '2026-10-07', ['project_id' => $acme->id]);

    $bar = gantt()
        ->query(fn () => Task::query()->withCount('project'))
        ->label(fn (Task $task) => "{$task->title} ({$task->project_count})")
        ->move((string) $task->id, '2026-10-12 00:00');

    expect($bar['label'])->toBe('A (1)');
});

it('undoes the save when afterMove() fails, so the browser putting the bar back matches the database', function () {
    $task = task('A', '2026-10-05', '2026-10-07');
    Event::fake([BarMoved::class]);

    $gantt = gantt()->afterMove(fn () => throw new RuntimeException('Mail server down.'));

    expect(fn () => $gantt->move((string) $task->id, '2026-10-12 00:00'))->toThrow(RuntimeException::class)
        ->and($task->fresh()->starts_on->toDateString())->toBe('2026-10-05');
});

it('moves a whole-day record by whole days on a chart that snaps to hours', function () {
    $task = task('A', '2026-10-05', '2026-10-07');
    Event::fake([BarMoved::class]);

    // the end handle dragged back an hour: nothing to save on a date column
    $bar = gantt()->snapTo(Unit::Hour)->move((string) $task->id, '2026-10-05 00:00', '2026-10-07 23:00');

    expect($bar['end'])->toBe('2026-10-08 00:00')
        ->and($task->fresh()->ends_on->toDateString())->toBe('2026-10-07');
    Event::assertNotDispatched(BarMoved::class);

    gantt()->snapTo(Unit::Hour)->move((string) $task->id, '2026-10-06 03:00');

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-06');
});

it('refuses a maxDuration() shorter than the minDuration()', function () {
    expect(fn () => gantt()->minDuration(days: 3)->maxDuration(days: 2)->getMaxMinutes())->toThrow(LogicException::class);
});

/* ------------------------------------------------------------------ creating */

it('prefills whole days on an uncast DATE column, from a stored value', function () {
    UncastTask::query()->create(['title' => 'Stored', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02']);

    $gantt = Gantt::make()->query(fn () => UncastTask::query())->start('starts_on')->end('ends_on');

    expect($gantt->prefill('2026-10-10 00:00', '2026-10-11 00:00'))->toBe(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-10']);
});

/* ------------------------------------------------------------------ bars */

it('keeps only colours, so a colour from user data cannot style the bar', function () {
    expect(Bar::make()->color('red;position:fixed;inset:0')->toArray())->not->toHaveKey('color')
        ->and(Bar::make()->color('url(https://example.test/x)')->toArray())->not->toHaveKey('color')
        ->and(Bar::make()->color('rgb(var(--x))')->toArray())->not->toHaveKey('color')
        ->and(Bar::make()->color('#0ea5e9')->toArray()['color'])->toBe('#0ea5e9')
        ->and(Bar::make()->color('oklch(0.6 0.2 250 / 50%)')->toArray()['color'])->toBe('oklch(0.6 0.2 250 / 50%)')
        ->and(Bar::make()->color('brand')->toArray()['color'])->toBe('brand')
        ->and(Bar::make()->color('var(--brand-500)')->toArray()['color'])->toBe('var(--brand-500)');
});

it('takes the same colours for a group as for a bar', function () {
    expect(Group::make(1)->color(Color::Sky)->getColor())->toBe(Color::Sky[500])
        ->and(Group::make(1)->color('red}body{display:none')->getColor())->toBeNull()
        ->and(Group::make(1)->color(fn () => 'emerald')->getColor())->toBe('emerald');
});

it('reads an enum label as its label', function () {
    task('A', '2026-10-05', '2026-10-06');

    expect(gantt()->label(fn () => TaskStatus::Todo)->getState()['bars'][0]['label'])->toBe('To do');
});

/* ------------------------------------------------------------------ zoom levels */

it('offers the hour zoom to a chart that snaps to hours, and keeps the opening scale among those offered', function () {
    expect(gantt()->getScales())->toBe([Scale::Day, Scale::Week, Scale::Month])
        ->and(shifts()->getScales())->toBe([Scale::Hour, Scale::Day, Scale::Week, Scale::Month])
        ->and(gantt()->scales(['week', 'month'])->scale('day')->getScale())->toBe(Scale::Week)
        ->and(gantt()->scales([Scale::Hour, 'day'])->getScales())->toBe([Scale::Hour, Scale::Day]);
});

/* ------------------------------------------------------------------ formats and words */

it('takes the date formats from the locale unless the config sets them', function () {
    app()->setLocale('de');

    expect(Gantt::dateFormat())->toBe('j. M Y');

    config(['filament-gantt.date_format' => 'd/m/Y']);

    expect(Gantt::dateFormat())->toBe('d/m/Y');
});

it('says durations with each language\'s plural forms', function (string $locale, int $count, string $words) {
    app()->setLocale($locale);

    expect(trans_choice('packstub-gantt::gantt.days', $count, ['count' => $count]))->toBe($words);
})->with([
    ['ru', 1, '1 день'], ['ru', 3, '3 дня'], ['ru', 11, '11 дней'], ['ru', 21, '21 день'], ['ru', 24, '24 дня'], ['ru', 25, '25 дней'],
    ['ro', 1, '1 zi'], ['ro', 19, '19 zile'], ['ro', 20, '20 de zile'], ['ro', 101, '101 zile'], ['ro', 120, '120 de zile'],
    ['de', 1, '1 Tag'], ['de', 2, '2 Tage'], ['en', 1, '1 day'], ['en', 21, '21 days'],
]);
