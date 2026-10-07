<?php

use Packstub\Gantt\Enums\Unit;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Tests\Fixtures\Project;
use Packstub\Gantt\Tests\Fixtures\Shift;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\User;
use Packstub\Gantt\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

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
