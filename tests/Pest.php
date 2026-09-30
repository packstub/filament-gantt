<?php

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
