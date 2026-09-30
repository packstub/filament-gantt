<?php

namespace Packstub\Gantt\Tests\Fixtures;

class TaskPolicy
{
    public static bool $allowed = true;

    public function viewAny(User $user): bool
    {
        return static::$allowed;
    }

    public function view(User $user, Task $task): bool
    {
        return static::$allowed;
    }

    public function create(User $user): bool
    {
        return static::$allowed;
    }

    public function update(User $user, Task $task): bool
    {
        return static::$allowed;
    }

    public function delete(User $user, Task $task): bool
    {
        return static::$allowed;
    }
}
