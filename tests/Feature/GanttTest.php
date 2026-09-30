<?php

use Carbon\CarbonImmutable;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Packstub\Gantt\Bar;
use Packstub\Gantt\Enums\Scale;
use Packstub\Gantt\Enums\Unit;
use Packstub\Gantt\Events\BarMoved;
use Packstub\Gantt\Exceptions\MoveRejected;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Group;
use Packstub\Gantt\Tests\Fixtures\Project;
use Packstub\Gantt\Tests\Fixtures\Shift;
use Packstub\Gantt\Tests\Fixtures\Task;

function gantt(): Gantt
{
    return Gantt::make()
        ->query(fn () => Task::query()->with('project'))
        ->start('starts_on')->end('ends_on')
        ->label('title')
        ->progress('progress')
        ->groupBy('project', label: 'project.name');
}

function shifts(): Gantt
{
    return Gantt::make()
        ->query(fn () => Shift::query())
        ->start('starts_at')->end('ends_at')
        ->label('title')
        ->groupBy('room')
        ->snapTo(Unit::Hour);
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-14 10:00:00'));

afterEach(function () {
    CarbonImmutable::setTestNow();
    date_default_timezone_set('UTC');
});

/** Run the app in another timezone, the way config('app.timezone') does at boot. */
function inTimezone(string $timezone): void
{
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);
}

/* ------------------------------------------------------------------ bars */

it('maps whole-day records to half-open bars, the end day included', function () {
    task('Write docs', '2026-10-05', '2026-10-07', ['progress' => 40]);

    $bar = gantt()->getBars()[0];

    expect($bar)->toMatchArray([
        'id' => '1',
        'group' => '',
        'start' => '2026-10-05 00:00',
        'end' => '2026-10-08 00:00',
        'allDay' => true,
        'milestone' => false,
        'label' => 'Write docs',
        'progress' => 40,
        'movable' => true,
        'resizable' => true,
    ])->and($bar)->not->toHaveKey('color');
});

it('maps datetime records as they are, and tells the browser they have times', function () {
    shift('Morning', '2026-10-05 08:00:00', '2026-10-05 12:30:00', ['room' => 'A']);

    expect(shifts()->getBars()[0])->toMatchArray(['start' => '2026-10-05 08:00', 'end' => '2026-10-05 12:30', 'allDay' => false, 'group' => 'A', 'milestone' => false]);
});

it('reads the dates as wall-clock moments whatever the app\'s timezone', function () {
    inTimezone('Europe/Bucharest');
    task('Docs', '2026-10-25', '2026-10-26'); // the day the clocks go back
    shift('Night', '2026-10-24 22:00:00', '2026-10-25 04:00:00');

    expect(gantt()->getBars()[0])->toMatchArray(['start' => '2026-10-25 00:00', 'end' => '2026-10-27 00:00'])
        ->and(shifts()->getBars()[0])->toMatchArray(['start' => '2026-10-24 22:00', 'end' => '2026-10-25 04:00'])
        ->and(gantt()->getWindow()['start']->toDateString())->toBe('2026-10-18')
        ->and(gantt()->startDate('2026-10-01')->getWindow()['start']->timezoneName)->toBe('UTC');
});

it('draws a milestone for a null end, or a datetime end equal to the start', function () {
    task('Launch', '2026-10-20', null);
    shift('Handover', '2026-10-05 09:00:00', '2026-10-05 09:00:00');
    task('One day', '2026-10-06', '2026-10-06');

    expect(gantt()->getBars()[1])->toMatchArray(['label' => 'Launch', 'start' => '2026-10-20 00:00', 'end' => '2026-10-20 00:00', 'milestone' => true, 'resizable' => false])
        ->and(shifts()->getBars()[0])->toMatchArray(['milestone' => true, 'end' => '2026-10-05 09:00'])
        ->and(gantt()->getBars()[0])->toMatchArray(['label' => 'One day', 'milestone' => false, 'end' => '2026-10-07 00:00']);
});

it('reads milestones from an attribute or a closure when told to', function () {
    task('Deadline', '2026-10-06', '2026-10-06', ['status' => 'milestone']);
    task('Work', '2026-10-06', '2026-10-09');

    $bars = gantt()->milestone(fn (Task $t) => $t->status === 'milestone')->getBars();

    expect(array_column($bars, 'milestone'))->toBe([true, false]);
});

it('skips records without a start and sorts by start, then by the sort attribute', function () {
    task('Later', '2026-10-09', '2026-10-10');
    task('Unscheduled', null, null);
    task('Earlier', '2026-10-05', '2026-10-06');

    expect(array_column(gantt()->getBars(), 'label'))->toBe(['Earlier', 'Later'])
        ->and(array_column(gantt()->sortBy('title', 'desc')->getBars(), 'label'))->toBe(['Later', 'Earlier']);
});

it('reads colours from attributes, closures, enums and Filament palettes', function () {
    $acme = project('Acme', 'amber');
    task('A', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);

    expect(gantt()->color('project.color')->getBars()[0]['color'])->toBe('amber')
        ->and(gantt()->color(fn (Task $t) => Color::Sky)->getBars()[0]['color'])->toBe(Color::Sky[500])
        ->and(gantt()->color(fn () => '#123456')->getBars()[0]['color'])->toBe('#123456')
        ->and(Bar::make()->color(null)->toArray())->not->toHaveKey('color');
});

it('lets bar() shape the bar over the shortcuts', function () {
    task('Raw', '2026-10-05', '2026-10-06', ['progress' => 150]);

    $bar = gantt()->bar(fn (Task $t) => Bar::make()->label(strtoupper($t->title))->color('rose')->tooltip('Hi')->url('/tasks/1')->actions(['edit']))->getBars()[0];

    expect($bar)->toMatchArray(['label' => 'RAW', 'color' => 'rose', 'progress' => 100, 'tooltip' => 'Hi', 'url' => '/tasks/1', 'actions' => ['edit']]);
});

it('keeps only urls a bar may safely follow', function (string $url, ?string $kept) {
    expect(Bar::make()->url($url)->toArray()['url'] ?? null)->toBe($kept);
})->with([
    'javascript' => ['javascript:alert(1)', null],
    'javascript, disguised' => [" Java\nScript:alert(1)", null],
    'data' => ['data:text/html,hi', null],
    'vbscript' => ['vbscript:x', null],
    'protocol-relative host' => ['//evil.test/x', null],
    'protocol-relative host, disguised' => ["/\t/evil.test", null],
    'backslash host' => ['\\\\evil.test', null],
    'slash and backslash host' => ['/\\evil.test', null],
    'empty' => ['  ', null],
    'absolute path' => ['/tasks/1', '/tasks/1'],
    'relative path' => ['tasks/1?x=1#y', 'tasks/1?x=1#y'],
    'https' => ['https://example.test/tasks/1', 'https://example.test/tasks/1'],
    'mailto' => ['mailto:ana@example.test', 'mailto:ana@example.test'],
    'tel' => ['tel:+40700000000', 'tel:+40700000000'],
]);

/* ------------------------------------------------------------------ groups */

it('groups by a relation with labels, in order of first appearance', function () {
    $beta = project('Beta');
    $acme = project('Acme');
    task('B1', '2026-10-05', '2026-10-06', ['project_id' => $beta->id]);
    task('A1', '2026-10-06', '2026-10-07', ['project_id' => $acme->id]);
    task('None', '2026-10-07', '2026-10-08');

    $state = gantt()->getState();

    expect(array_column($state['bars'], 'group'))->toBe([(string) $beta->id, (string) $acme->id, ''])
        ->and(array_map(fn ($g) => [$g['key'], $g['label'], $g['count']], $state['groups']))->toBe([
            [(string) $beta->id, 'Beta', 1],
            [(string) $acme->id, 'Acme', 1],
            ['', 'Ungrouped', 1],
        ]);
});

it('groups by a column, with a headline label', function () {
    task('A', '2026-10-05', '2026-10-06', ['status' => 'in_review']);

    $state = gantt()->groupBy('status')->getState();

    expect($state['bars'][0]['group'])->toBe('in_review')
        ->and($state['groups'][0])->toMatchArray(['key' => 'in_review', 'label' => 'In Review', 'count' => 1]);
});

it('lists the defined groups first, empty ones included, then the ones met on bars', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    task('Stray', '2026-10-05', '2026-10-06', ['project_id' => 99]);
    task('A', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);

    $gantt = gantt()->groups(fn () => Group::fromModels(Project::query()->orderBy('name')->get(), color: fn () => 'sky'));

    expect(array_map(fn ($g) => [$g['key'], $g['label'], $g['count'], $g['color']], $gantt->getState()['groups']))->toBe([
        [(string) $acme->id, 'Acme', 1, 'sky'],
        [(string) $beta->id, 'Beta', 0, 'sky'],
        ['99', '99', 1, null],
    ])->and(Group::make('x')->label(fn () => 'Ex')->collapsed()->toArray())->toMatchArray(['label' => 'Ex', 'collapsed' => true]);
});

it('knows the attribute a new record\'s group goes to', function () {
    expect(gantt()->getGroupForeignKey())->toBe('project_id')
        ->and(gantt()->groupBy('status')->getGroupForeignKey())->toBe('status')
        ->and(gantt()->groupBy(null)->getGroupForeignKey())->toBeNull()
        ->and(gantt()->groupBy(null)->isGrouped())->toBeFalse()
        ->and(gantt()->getState()['groups'])->toBe([]);
});

/* ------------------------------------------------------------------ window */

it('pads the window around the bars and keeps it within the configured bounds', function () {
    task('A', '2026-10-05', '2026-10-09');
    task('B', '2026-10-20', '2026-11-02');

    $window = gantt()->getWindow();

    expect($window['start']->toDateString())->toBe('2026-09-28')
        ->and($window['end']->toDateString())->toBe('2026-11-16');

    $empty = Gantt::make()->query(fn () => Task::query()->where('id', 0))->start('starts_on')->end('ends_on')->getWindow();

    expect($empty['start']->toDateString())->toBe('2026-10-07')
        ->and($empty['end']->toDateString())->toBe('2026-11-03'); // 28 days at least
});

it('takes the definition\'s dates, then the browser\'s, and clamps both', function () {
    task('A', '2026-10-05', '2026-10-09');

    $gantt = gantt()->startDate('2026-10-01')->endDate('2026-10-03');
    $window = $gantt->getWindow();

    expect($window['start']->toDateString())->toBe('2026-10-01')
        ->and($window['end']->toDateString())->toBe('2026-10-28') // stretched to the minimum
        ->and($gantt->getWindow('2027-01-01', '2029-06-01')['end']->toDateString())->toBe('2028-12-30') // 730 days at most
        ->and($gantt->getWindow('garbage', '2026-12-31')['start']->toDateString())->toBe('2026-10-01')
        ->and($gantt->getWindow('2026-12-31', '2026-01-01')['start']->toDateString())->toBe('2026-12-31');
});

it('loads only the bars overlapping the window, milestones by their start', function () {
    task('Before', '2026-09-01', '2026-09-05');
    task('Overlaps start', '2026-09-28', '2026-10-02');
    task('Inside', '2026-10-05', '2026-10-06');
    task('Milestone in', '2026-10-15', null);
    task('Milestone out', '2026-12-15', null);
    task('After', '2026-12-01', '2026-12-05');

    $bars = gantt()->getBars(from: CarbonImmutable::parse('2026-10-01'), until: CarbonImmutable::parse('2026-10-31'));

    expect(array_column($bars, 'label'))->toBe(['Overlaps start', 'Inside', 'Milestone in']);
});

it('asks for the window with plain ranges on the columns, so their indexes stay in use', function () {
    shift('Ends as the window opens', '2026-09-30 22:00:00', '2026-10-01 00:00:00');
    shift('Starts as the window closes', '2026-10-31 23:30:00', '2026-11-01 02:00:00');
    shift('Starts after', '2026-11-01 00:00:00', '2026-11-01 02:00:00');
    shift('Ends before', '2026-09-30 08:00:00', '2026-09-30 23:59:00');

    DB::enableQueryLog();
    $bars = shifts()->getBars(from: CarbonImmutable::parse('2026-10-01'), until: CarbonImmutable::parse('2026-10-31'));
    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect(array_column($bars, 'label'))->toBe(['Ends as the window opens', 'Starts as the window closes'])
        ->and($sql)->not->toContain('strftime', 'date(')
        ->and($sql)->toContain('"starts_at" < ?', '"ends_at" >= ?');
});

/* ------------------------------------------------------------------ filters */

it('applies select and date-range filters and ignores values it did not offer', function () {
    $acme = project('Acme');
    task('A', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);
    task('B', '2026-10-20', '2026-10-21');

    $gantt = gantt()->filters([
        Filter::select('project_id')->options(fn () => [$acme->id => 'Acme']),
        Filter::dateRange('starts_on'),
    ]);

    expect(array_column($gantt->getBars(['project_id' => $acme->id]), 'label'))->toBe(['A'])
        ->and(array_column($gantt->getBars(['project_id' => 999]), 'label'))->toBe(['A', 'B'])
        ->and(array_column($gantt->getBars(['starts_on' => ['from' => '2026-10-10']]), 'label'))->toBe(['B'])
        ->and(array_column($gantt->getBars(['starts_on' => ['until' => '2026-10-10']]), 'label'))->toBe(['A'])
        ->and(array_column($gantt->getBars(['starts_on' => ['from' => "2026-10-10' or 1=1"]]), 'label'))->toBe(['A', 'B'])
        ->and(array_column($gantt->getBars(['starts_on' => 'garbage']), 'label'))->toBe(['A', 'B'])
        ->and(array_column($gantt->getBars(['project_id' => ['x']]), 'label'))->toBe(['A', 'B'])
        ->and($gantt->getFilters()[0]->getLabel())->toBe('Project')
        ->and($gantt->getFilters()[1]->toArray())->toMatchArray(['name' => 'starts_on', 'label' => 'Starts On', 'type' => 'dateRange', 'options' => []]);
});

it('narrows a date range with plain ranges on the column, the last day included whatever the cast', function () {
    shift('First thing', '2026-10-10 00:00:00', '2026-10-10 02:00:00');
    shift('Late on the last day', '2026-10-12 23:30:00', '2026-10-13 02:00:00');
    shift('The day after', '2026-10-13 00:00:00', '2026-10-13 02:00:00');
    shift('The day before', '2026-10-09 23:59:00', '2026-10-10 02:00:00');

    $gantt = shifts()->filters([Filter::dateRange('starts_at')]);

    DB::enableQueryLog();
    $bars = $gantt->getBars(['starts_at' => ['from' => '2026-10-10', 'until' => '2026-10-12']]);
    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect(array_column($bars, 'label'))->toBe(['First thing', 'Late on the last day'])
        ->and($sql)->not->toContain('strftime', 'date(')
        ->and($sql)->toContain('"starts_at" >= ?', '"starts_at" < ?')
        ->and(array_column($gantt->getBars(['starts_at' => ['until' => '2026-10-09']]), 'label'))->toBe(['The day before']);
});

it('lets a filter narrow the query its own way', function () {
    task('Late', '2026-10-05', '2026-10-06', ['progress' => 10]);
    task('Done', '2026-10-05', '2026-10-06', ['progress' => 100]);

    $gantt = gantt()->filters([Filter::make('state')->options(['open' => 'Open'])->query(fn ($q, $v) => $q->where('progress', '<', 100))]);

    expect(array_column($gantt->getBars(['state' => 'open']), 'label'))->toBe(['Late']);
});

/* ------------------------------------------------------------------ moves */

it('moves a whole-day bar, keeping its duration, and answers with the bar as saved', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');

    $bar = gantt()->move((string) $task->id, '2026-10-12 00:00');

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-12')
        ->and($task->fresh()->ends_on->toDateString())->toBe('2026-10-14')
        ->and($bar)->toMatchArray(['id' => (string) $task->id, 'start' => '2026-10-12 00:00', 'end' => '2026-10-15 00:00']);
});

it('resizes either edge of a whole-day bar', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');

    gantt()->move((string) $task->id, '2026-10-05 00:00', '2026-10-10 00:00');
    expect($task->fresh()->ends_on->toDateString())->toBe('2026-10-09');

    gantt()->move((string) $task->id, '2026-10-07 00:00', '2026-10-10 00:00');
    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-07');
});

it('snaps the delta to the unit on the server whatever the browser sent, relative to where the bar was', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');
    $shift = shift('Morning', '2026-10-05 08:00:00', '2026-10-05 12:00:00');

    gantt()->move((string) $task->id, '2026-10-06 13:45', '2026-10-09 02:10'); // +1.57 days, +1.09 days
    shifts()->move((string) $shift->id, '2026-10-05 09:20', '2026-10-05 13:59'); // +1.33 h, +1.98 h

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-07')
        ->and($task->fresh()->ends_on->toDateString())->toBe('2026-10-08')
        ->and($shift->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-05 09:00')
        ->and($shift->fresh()->ends_at->format('Y-m-d H:i'))->toBe('2026-10-05 14:00');

    // exactly half a unit rounds up either way, like the browser's Math.round
    $task->update(['starts_on' => '2026-10-05', 'ends_on' => '2026-10-07']);
    gantt()->move((string) $task->id, '2026-10-04 12:00');
    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-05');
    gantt()->move((string) $task->id, '2026-10-05 12:00');
    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-06');
});

it('moves a datetime record by whole days with the default unit, keeping its time of day', function () {
    $shift = shift('Morning', '2026-10-05 08:00:00', '2026-10-05 12:00:00');
    $gantt = Gantt::make()->query(fn () => Shift::query())->start('starts_at')->end('ends_at'); // snapTo(Unit::Day)

    $gantt->move((string) $shift->id, '2026-10-06 08:00', '2026-10-06 12:00'); // what a one-day drag sends
    expect($shift->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-06 08:00')
        ->and($shift->fresh()->ends_at->format('Y-m-d H:i'))->toBe('2026-10-06 12:00');

    $gantt->move((string) $shift->id, '2026-10-07 08:20'); // a stray 20 minutes round away, the duration is kept
    expect($shift->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-07 08:00')
        ->and($shift->fresh()->ends_at->format('Y-m-d H:i'))->toBe('2026-10-07 12:00');
});

it('moves a milestone and leaves a null end null', function () {
    $task = task('Launch', '2026-10-20', null);
    $shift = shift('Handover', '2026-10-05 09:00:00', '2026-10-05 09:00:00');

    gantt()->move((string) $task->id, '2026-10-22 00:00', '2026-10-25 00:00');
    shifts()->move((string) $shift->id, '2026-10-06 10:00');

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-22')
        ->and($task->fresh()->ends_on)->toBeNull()
        ->and($shift->fresh()->ends_at->format('Y-m-d H:i'))->toBe('2026-10-06 10:00');
});

it('shifts the real end of a milestone flagged by milestone() along with its start', function () {
    $task = task('Deadline', '2026-10-06', '2026-10-09', ['status' => 'milestone']);
    $gantt = gantt()->milestone(fn (Task $t) => $t->status === 'milestone');

    $bar = $gantt->move((string) $task->id, '2026-10-08 00:00');

    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-08')
        ->and($task->fresh()->ends_on->toDateString())->toBe('2026-10-11')
        ->and($bar)->toMatchArray(['milestone' => true, 'start' => '2026-10-08 00:00', 'end' => '2026-10-08 00:00']);

    $gantt->move((string) $task->id, '2026-10-01 00:00');
    expect($task->fresh()->ends_on->toDateString())->toBe('2026-10-04');
});

it('keeps whole days whole across a daylight-saving change', function (string $timezone, string $from, string $until, string $to, string $stored) {
    inTimezone($timezone);
    Event::fake([BarMoved::class]);
    $task = task('Move me', $from, $until);
    $gantt = gantt()->resizable(false);

    // a plain move, with the end the browser computed and without it
    $gantt->move((string) $task->id, "{$to} 00:00", CarbonImmutable::parse($to, 'UTC')->addDays(3)->format('Y-m-d H:i'));
    expect($task->fresh()->starts_on->toDateString())->toBe($to)
        ->and($task->fresh()->ends_on->toDateString())->toBe($stored);
    Event::assertDispatched(BarMoved::class, fn (BarMoved $e) => ! $e->isResize() && $e->start->timezoneName === $timezone && $e->start->format('Y-m-d H:i') === "{$to} 00:00");

    $task->update(['starts_on' => $from, 'ends_on' => $until]);
    $gantt->move((string) $task->id, "{$to} 00:00");
    expect($task->fresh()->ends_on->toDateString())->toBe($stored);

    // a datetime record keeps its wall-clock hours too
    $shift = shift('Night', "{$from} 22:00:00", CarbonImmutable::parse($from, 'UTC')->addDay()->format('Y-m-d').' 04:00:00');
    shifts()->snapTo(Unit::Day)->move((string) $shift->id, "{$to} 22:00");
    expect($shift->fresh()->starts_at->format('Y-m-d H:i'))->toBe("{$to} 22:00")
        ->and($shift->fresh()->ends_at->format('Y-m-d H:i'))->toBe(CarbonImmutable::parse($to, 'UTC')->addDay()->format('Y-m-d').' 04:00');
})->with([
    'Europe/Bucharest, clocks back on 2026-10-25' => ['Europe/Bucharest', '2026-10-21', '2026-10-23', '2026-10-24', '2026-10-26'],
    'America/New_York, clocks back on 2026-11-01' => ['America/New_York', '2026-10-28', '2026-10-30', '2026-10-31', '2026-11-02'],
    'Europe/Bucharest, clocks forward on 2026-03-29' => ['Europe/Bucharest', '2026-03-25', '2026-03-27', '2026-03-28', '2026-03-30'],
]);

it('saves a move into the hour a spring-forward change skips an hour later, whichever path writes it', function () {
    inTimezone('Europe/Bucharest'); // 2026-03-29 03:00 becomes 04:00
    $default = shift('Default', '2026-03-28 03:30:00', '2026-03-28 11:30:00');
    $custom = shift('Custom', '2026-03-28 03:30:00', '2026-03-28 11:30:00');
    $seen = null;

    shifts()->snapTo(Unit::Day)->move((string) $default->id, '2026-03-29 03:30');
    shifts()->snapTo(Unit::Day)->moveUsing(function (Shift $shift, CarbonImmutable $start, CarbonImmutable $end) use (&$seen) {
        $seen = $start->format('Y-m-d H:i');
        $shift->update(['starts_at' => $start, 'ends_at' => $end]);
    })->move((string) $custom->id, '2026-03-29 03:30');

    // The closure's copy has no valid 03:30 in that zone, and neither has the column: Eloquent resolves both forward.
    expect($seen)->toBe('2026-03-29 04:30')
        ->and(DB::table('shifts')->where('id', $default->id)->value('starts_at'))->toBe('2026-03-29 04:30:00')
        ->and(DB::table('shifts')->where('id', $custom->id)->value('starts_at'))->toBe('2026-03-29 04:30:00');
});

it('hands the closures and the event the moments in the app\'s timezone, so a comparison with now() holds', function () {
    inTimezone('Europe/Bucharest');
    CarbonImmutable::setTestNow('2026-10-14 10:00:00'); // 10:00 on the wall clock
    Event::fake([BarMoved::class]);
    $shift = shift('Early', '2026-10-14 07:00:00', '2026-10-14 08:00:00');
    $seen = [];

    $gantt = shifts()
        ->canMove(function (Shift $s, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $previousStart) use (&$seen) {
            $seen['canMove'] = [$start->timezoneName, $start->format('Y-m-d H:i'), $start->isPast(), $end->isPast(), $start < now(), $previousStart->isPast()];

            return true;
        })
        ->afterMove(function (Shift $s, CarbonImmutable $start, CarbonImmutable $end) use (&$seen) {
            $seen['afterMove'] = [$start->timezoneName, $end->format('Y-m-d H:i'), $end->isPast()];
        });

    $gantt->move((string) $shift->id, '2026-10-14 08:00');

    expect($seen['canMove'])->toBe(['Europe/Bucharest', '2026-10-14 08:00', true, true, true, true])
        ->and($seen['afterMove'])->toBe(['Europe/Bucharest', '2026-10-14 09:00', true])
        ->and($shift->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-14 08:00');
    Event::assertDispatched(BarMoved::class, fn (BarMoved $e) => $e->start->timezoneName === 'Europe/Bucharest'
        && $e->start->format('Y-m-d H:i') === '2026-10-14 08:00'
        && $e->previousStart->format('Y-m-d H:i') === '2026-10-14 07:00'
        && ! $e->isResize());

    // a move into the future is the future, an hour ahead is not the past
    $gantt->move((string) $shift->id, '2026-10-14 11:00');
    expect($seen['canMove'][2])->toBeFalse()
        ->and($seen['afterMove'][2])->toBeFalse();
});

it('refuses to relocate a bar that cannot move by dragging both its edges', function () {
    $task = task('Fixed', '2026-10-05', '2026-10-07');
    $gantt = gantt()->movable(false)->resizable(true);

    expect(fn () => $gantt->move((string) $task->id, '2026-10-20 00:00', '2026-10-24 00:00'))->toThrow(MoveRejected::class, 'cannot be moved')
        ->and($task->fresh()->starts_on->toDateString())->toBe('2026-10-05');

    // one edge at a time is a resize, and allowed
    $gantt->move((string) $task->id, '2026-10-05 00:00', '2026-10-10 00:00');
    $gantt->move((string) $task->id, '2026-10-06 00:00', '2026-10-10 00:00');
    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-06')
        ->and($task->fresh()->ends_on->toDateString())->toBe('2026-10-09');

    // both edges with the duration kept is a move: refused here, allowed when movable
    expect(fn () => $gantt->move((string) $task->id, '2026-10-07 00:00', '2026-10-11 00:00'))->toThrow(MoveRejected::class, 'cannot be moved');
    gantt()->resizable(false)->move((string) $task->id, '2026-10-07 00:00', '2026-10-11 00:00');
    expect($task->fresh()->starts_on->toDateString())->toBe('2026-10-07');

    // both edges and another length needs both rights
    expect(fn () => gantt()->resizable(false)->move((string) $task->id, '2026-10-08 00:00', '2026-10-13 00:00'))->toThrow(MoveRejected::class, 'cannot be resized');
    gantt()->move((string) $task->id, '2026-10-08 00:00', '2026-10-13 00:00');
    expect($task->fresh()->ends_on->toDateString())->toBe('2026-10-12');
});

it('refuses what the rules do not allow, whatever the browser sent', function (Closure $define, string $start, ?string $end, string $message) {
    $task = task('Build', '2026-10-05', '2026-10-07');

    expect(fn () => $define(gantt())->move((string) $task->id, $start, $end))->toThrow(MoveRejected::class, $message)
        ->and($task->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and($task->fresh()->ends_on->toDateString())->toBe('2026-10-07');
})->with([
    'not movable' => [fn (Gantt $g) => $g->movable(false), '2026-10-06 00:00', null, 'cannot be moved'],
    'not movable for this record' => [fn (Gantt $g) => $g->movable(fn (Task $t) => $t->title !== 'Build'), '2026-10-06 00:00', null, 'cannot be moved'],
    'not resizable' => [fn (Gantt $g) => $g->resizable(false), '2026-10-05 00:00', '2026-10-10 00:00', 'cannot be resized'],
    'read-only' => [fn (Gantt $g) => $g->readOnly(), '2026-10-06 00:00', null, 'read-only'],
    'too short' => [fn (Gantt $g) => $g->minDuration(days: 5), '2026-10-05 00:00', '2026-10-07 00:00', 'shorter than 5 days'],
    'zero length' => [fn (Gantt $g) => $g, '2026-10-05 00:00', '2026-10-05 00:00', 'shorter than 1 day'],
    'too long' => [fn (Gantt $g) => $g->maxDuration(days: 4), '2026-10-05 00:00', '2026-10-12 00:00', 'longer than 4 days'],
    'locked: moving into the past' => [fn (Gantt $g) => $g->lockedBefore(fn () => today()), '2026-10-01 00:00', null, 'before Oct 14, 2026'],
    'locked: the start is in the past' => [fn (Gantt $g) => $g->lockedBefore(today()), '2026-10-20 00:00', null, 'before Oct 14, 2026'],
    'locked: the end goes into the past' => [fn (Gantt $g) => $g->lockedBefore(today()), '2026-10-05 00:00', '2026-10-06 00:00', 'before Oct 14, 2026'],
    'custom closure says no' => [fn (Gantt $g) => $g->canMove(fn () => false), '2026-10-06 00:00', null, 'cannot move'],
    'custom closure says why' => [fn (Gantt $g) => $g->canMove(fn (Task $t, CarbonImmutable $s) => $s->isWeekend() ? 'Not on a weekend.' : true), '2026-10-10 00:00', null, 'Not on a weekend.'],
    'garbled date' => [fn (Gantt $g) => $g, 'not a date', null, 'did not go through'],
]);

it('reads only the dates the chart sends: a real calendar date in a four-digit year, with a time or without', function (string $start, ?string $end) {
    $task = task('Build', '2026-10-05', '2026-10-07');

    expect(fn () => gantt()->move((string) $task->id, $start, $end))->toThrow(MoveRejected::class, 'did not go through')
        ->and($task->fresh()->starts_on->toDateString())->toBe('2026-10-05')
        ->and(gantt()->createAction(CreateAction::make())->prefill($start, $end))->toBe([]);
})->with([
    'the zero date' => ['0000-00-00 00:00', null],
    'a year below 1000' => ['0999-10-06 00:00', null],
    'a day that does not exist' => ['2026-02-30 00:00', null],
    'an hour that does not exist' => ['2026-10-06 24:00', null],
    'a minute that does not exist' => ['2026-10-06 10:60', null],
    'a two-digit year' => ['26-10-06 00:00', null],
    'a timestamp' => ['1790000000', null],
    'a phrase Carbon would guess at' => ['next monday', null],
    'a garbled end' => ['2026-10-06 00:00', 'garbage'],
]);

it('accepts the date shapes the chart sends', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');
    $shift = shift('Shift', '2026-10-05 08:00:00', '2026-10-05 12:00:00');

    expect(gantt()->move((string) $task->id, '2026-10-06')['start'])->toBe('2026-10-06 00:00')
        ->and(gantt()->move((string) $task->id, ' 2026-10-07 00:00 ')['start'])->toBe('2026-10-07 00:00')
        ->and(gantt()->move((string) $task->id, '2026-10-08 00:00:00')['start'])->toBe('2026-10-08 00:00')
        ->and(shifts()->move((string) $shift->id, '2026-10-05T09:00', '2026-10-05T13:00')['start'])->toBe('2026-10-05 09:00');
});

it('names the lock with its time when it is not a midnight', function () {
    $shift = shift('Shift', '2026-10-14 11:00:00', '2026-10-14 12:00:00');

    expect(fn () => shifts()->lockedBefore(fn () => now())->move((string) $shift->id, '2026-10-14 09:00'))->toThrow(MoveRejected::class, 'before Oct 14, 2026 10:00.')
        ->and(fn () => shifts()->lockedBefore(today())->move((string) $shift->id, '2026-10-13 09:00'))->toThrow(MoveRejected::class, 'before Oct 14, 2026.')
        ->and($shift->fresh()->starts_at->format('Y-m-d H:i'))->toBe('2026-10-14 11:00');
});

it('lets a bar that started before the lock change its end, after the lock', function () {
    $task = task('Ongoing', '2026-10-05', '2026-10-20');

    gantt()->lockedBefore(today())->move((string) $task->id, '2026-10-05 00:00', '2026-10-25 00:00');

    expect($task->fresh()->ends_on->toDateString())->toBe('2026-10-24');
});

it('refuses a bar outside the query', function () {
    $task = task('Elsewhere', '2026-10-05', '2026-10-07');

    $gantt = gantt()->query(fn () => Task::query()->where('title', '!=', 'Elsewhere'));

    expect(fn () => $gantt->move((string) $task->id, '2026-10-06 00:00'))->toThrow(MoveRejected::class, 'no longer on the chart');
});

it('runs the app\'s move: a MoveRejected refuses with its message, any other exception propagates as it is', function () {
    $task = task('Build', '2026-10-05', '2026-10-07');

    $gantt = gantt()->moveUsing(function (Task $record, CarbonImmutable $start) {
        throw_if($start->isWeekend(), new MoveRejected('Not on a weekend.'));

        throw new RuntimeException('Something else broke.');
    });

    expect(fn () => $gantt->move((string) $task->id, '2026-10-10 00:00'))->toThrow(MoveRejected::class, 'Not on a weekend.')
        ->and(fn () => $gantt->move((string) $task->id, '2026-10-12 00:00'))->toThrow(RuntimeException::class, 'Something else broke.')
        ->and($task->fresh()->starts_on->toDateString())->toBe('2026-10-05');

    $seen = [];
    gantt()->moveUsing(function (Task $record, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $from, CarbonImmutable $until) use (&$seen) {
        $seen = [$start->toDateString(), $end->toDateString(), $from->toDateString(), $until->toDateString()];
        $record->update(['starts_on' => $start, 'ends_on' => $end->subDay(), 'title' => 'Build (moved)']);
    })->move((string) $task->id, '2026-10-12 00:00');

    expect($seen)->toBe(['2026-10-12', '2026-10-15', '2026-10-05', '2026-10-08'])
        ->and($task->fresh()->title)->toBe('Build (moved)');
});

it('dispatches BarMoved and runs afterMove, not on a no-op', function () {
    Event::fake([BarMoved::class]);
    $task = task('Build', '2026-10-05', '2026-10-07');
    $after = [];

    $gantt = gantt()->key('tasks')->afterMove(function (Task $t, CarbonImmutable $start) use (&$after) {
        $after[] = $start->toDateString();
    });

    $gantt->move((string) $task->id, '2026-10-05 00:00', '2026-10-08 00:00');
    Event::assertNotDispatched(BarMoved::class);

    $gantt->move((string) $task->id, '2026-10-05 00:00', '2026-10-10 00:00');
    Event::assertDispatched(BarMoved::class, fn (BarMoved $e) => $e->record->is($task)
        && $e->previousEnd->toDateString() === '2026-10-08'
        && $e->end->toDateString() === '2026-10-10'
        && $e->isResize()
        && $e->gantt === 'tasks');

    expect($after)->toBe(['2026-10-05']);
});

/* ------------------------------------------------------------------ settings */

it('reads scale, unit, first day of week and poll settings', function () {
    $gantt = gantt();

    expect($gantt->getScale())->toBe(Scale::Week)
        ->and($gantt->scale('month')->getScale())->toBe(Scale::Month)
        ->and($gantt->scale('nope')->getScale())->toBe(Scale::Week)
        ->and($gantt->getUnit())->toBe(Unit::Day)
        ->and($gantt->snapTo('hour')->getUnit())->toBe(Unit::Hour)
        ->and($gantt->getMinMinutes())->toBe(60)
        ->and($gantt->getFirstDayOfWeek())->toBe(1)
        ->and($gantt->firstDayOfWeek(7)->getFirstDayOfWeek())->toBe(7)
        ->and($gantt->poll('10s')->getPoll())->toBe(10000)
        ->and($gantt->poll('1m')->getPoll())->toBe(60000)
        ->and($gantt->poll(250)->getPoll())->toBe(1000)
        ->and($gantt->poll(null)->getPoll())->toBeNull()
        ->and($gantt->refreshOn(['task-updated'])->getRefreshOn())->toBe(['task-updated'])
        ->and(Scale::fromName(null))->toBe(Scale::Week);
});

it('registers a bar action from a name, an instance or a class', function () {
    expect(gantt()->barActions([EditAction::make()])->barAction('edit')->getBarAction())->toBe('edit')
        ->and(($g = gantt()->barAction(EditAction::make()->label('Open')))->getBarAction())->toBe('edit')
        ->and($g->getBarActions()[0]->getLabel())->toBe('Open')
        ->and(gantt()->barAction(EditAction::class)->getBarActions()[0]->getName())->toBe('edit')
        ->and(gantt()->createAction(CreateAction::make())->canCreate())->toBeTrue()
        ->and(gantt()->createAction(CreateAction::make())->readOnly()->canCreate())->toBeFalse()
        ->and(gantt()->canCreate())->toBeFalse();
});

it('prefills a create form with the clicked spot, the group only when the chart knows it', function () {
    $acme = project('Acme');
    $other = project('Other'); // exists, but has no row on the chart
    task('A', '2026-10-01', '2026-10-02', ['project_id' => $acme->id]);
    shift('Taken', '2026-10-05 08:00:00', '2026-10-05 12:00:00', ['room' => 'A']);
    $gantt = gantt()->createAction(CreateAction::make());
    $rooms = shifts()->createAction(CreateAction::make());

    expect($gantt->prefill('2026-10-06 00:00', '2026-10-09 00:00', (string) $acme->id))->toBe(['starts_on' => '2026-10-06', 'ends_on' => '2026-10-08', 'project_id' => (string) $acme->id])
        ->and($gantt->prefill('2026-10-06 00:00', '2026-10-09 00:00', (string) $other->id))->toBe(['starts_on' => '2026-10-06', 'ends_on' => '2026-10-08'])
        ->and($gantt->prefill('2026-10-06 00:00', '2026-10-09 00:00', '424242'))->toBe(['starts_on' => '2026-10-06', 'ends_on' => '2026-10-08'])
        ->and($gantt->prefill('2026-10-06 00:00', null, null))->toBe(['starts_on' => '2026-10-06', 'ends_on' => '2026-10-06'])
        ->and($rooms->prefill('2026-10-06 09:00', '2026-10-06 11:00', 'A'))->toBe(['starts_at' => '2026-10-06 09:00:00', 'ends_at' => '2026-10-06 11:00:00', 'room' => 'A'])
        ->and($rooms->prefill('2026-10-06 09:00', '2026-10-06 11:00', 'Z'))->toBe(['starts_at' => '2026-10-06 09:00:00', 'ends_at' => '2026-10-06 11:00:00'])
        ->and($gantt->prefill('nope', null, null))->toBe([]);
});

it('evaluates groups() once per chart, however often the groups are asked for', function () {
    $acme = project('Acme');
    task('A', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);
    $calls = 0;

    $gantt = gantt()->createAction(CreateAction::make())->groups(function () use (&$calls) {
        $calls++;

        return Group::fromModels(Project::query()->get());
    });

    $gantt->getState();
    $gantt->getState();
    $gantt->canCreateIn((string) $acme->id);
    $gantt->prefill('2026-10-06 00:00', null, (string) $acme->id);

    expect($calls)->toBe(1)
        ->and($gantt->getGroups([])[0]['label'])->toBe('Acme')
        ->and($gantt->groups([Group::make('planned')])->getGroups([])[0]['key'])->toBe('planned'); // a new list is read afresh
});

it('knows which groups a click may create in: the rows on the chart, never a related record that is not', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    task('Stray', '2026-10-05', '2026-10-06', ['project_id' => 99]);
    task('A', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);
    $gantt = gantt()->createAction(CreateAction::make());

    expect($gantt->canCreateIn(null))->toBeTrue()
        ->and($gantt->canCreateIn(''))->toBeTrue()
        ->and($gantt->canCreateIn((string) $acme->id))->toBeTrue() // a group a bar of the chart is in
        ->and($gantt->canCreateIn('99'))->toBeTrue() // even one with no related record
        ->and($gantt->canCreateIn((string) $beta->id))->toBeFalse() // a related record that exists, but no row a user could click
        ->and($gantt->canCreateIn('424242'))->toBeFalse()
        ->and($gantt->canCreateIn(['x']))->toBeFalse()
        ->and($gantt->groups([Group::make($acme->id)->label('Acme')])->canCreateIn((string) $beta->id))->toBeFalse() // an explicit list is the list
        ->and($gantt->groups([Group::make($beta->id)->label('Beta')])->canCreateIn((string) $beta->id))->toBeTrue()
        ->and($gantt->groups([Group::make('planned')])->groupBy(null)->canCreateIn('planned'))->toBeTrue()
        ->and($gantt->groupBy('project')->groups(null)->readOnly()->canCreateIn((string) $acme->id))->toBeFalse()
        ->and(gantt()->canCreateIn((string) $acme->id))->toBeFalse() // no create action
        ->and(gantt()->createAction(CreateAction::make())->creatableIn(fn (string $group) => $group === (string) $beta->id)->canCreateIn((string) $acme->id))->toBeFalse()
        ->and(gantt()->createAction(CreateAction::make())->creatableIn(fn (string $group) => $group === (string) $beta->id)->canCreateIn((string) $beta->id))->toBeTrue()
        ->and(gantt()->createAction(CreateAction::make())->groupBy('status')->canCreateIn('todo'))->toBeTrue() // the stray task's status
        ->and(gantt()->createAction(CreateAction::make())->groupBy('status')->canCreateIn('done'))->toBeFalse();
});
