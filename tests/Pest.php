<?php

use Illuminate\Support\Facades\Artisan;
use Packstub\Gantt\Enums\Unit;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Browser\Chart;
use Packstub\Gantt\Tests\Fixtures\ChartPage;
use Packstub\Gantt\Tests\Fixtures\Project;
use Packstub\Gantt\Tests\Fixtures\Shift;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\User;
use Packstub\Gantt\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Browser');

/**
 * The browser tests (tests/Browser, `composer test:browser`): Filament's and the chart's
 * assets are served from the public directory, the signed-in user is the panel's, and
 * the page a test opens is ChartPage, shaped by `define`.
 */
pest()->beforeEach(function () {
    ChartPage::$define = null;
    // Copied, never linked: once per run, so an edit to resources/dist is what the browser gets.
    static $published = false;
    $published = $published || Artisan::call('filament:assets') === 0;
    $this->actingAs(user());
})->in('Browser');

/**
 * Open the chart on four fixed weeks of the day scale (2026-03-02 to 2026-03-29, a Monday
 * to a Sunday), whatever today is. `define` shapes the chart: `fn (Gantt $gantt) => $gantt->…`.
 */
function chart(?Closure $define = null, string $query = 'scale=day&from=2026-03-02&until=2026-03-29', string $path = '/admin/chart'): Chart
{
    ChartPage::$define = $define;

    return new Chart(visit($path.($query === '' ? '' : '?'.$query)));
}

/** A task on whole days (`date` casts). @param  array<string, mixed>  $attributes */
function task(string $title, ?string $starts = null, ?string $ends = null, array $attributes = []): Task
{
    return Task::query()->create(['title' => $title, 'starts_on' => $starts, 'ends_on' => $ends, ...$attributes]);
}

/** A shift with times (`datetime` casts). @param  array<string, mixed>  $attributes */
function shift(string $title, ?string $starts = null, ?string $ends = null, array $attributes = []): Shift
{
    return Shift::query()->create(['title' => $title, 'starts_at' => $starts, 'ends_at' => $ends, ...$attributes]);
}

function project(string $name, ?string $color = null): Project
{
    return Project::query()->create(['name' => $name, 'color' => $color]);
}

function user(): User
{
    return User::query()->create(['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'secret']);
}

/** A chart of tasks (whole days) grouped by project. */
function gantt(): Gantt
{
    return Gantt::make()
        ->query(fn () => Task::query()->with('project'))
        ->start('starts_on')->end('ends_on')
        ->label('title')
        ->progress('progress')
        ->groupBy('project', label: 'project.name');
}

/** A chart of shifts (datetimes) grouped by room, snapped to hours. */
function shifts(): Gantt
{
    return Gantt::make()
        ->query(fn () => Shift::query())
        ->start('starts_at')->end('ends_at')
        ->label('title')
        ->groupBy('room')
        ->snapTo(Unit::Hour);
}
