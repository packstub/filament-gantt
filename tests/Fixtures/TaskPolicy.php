<?php

namespace Packstub\Gantt\Tests\Fixtures;

class TaskPolicy
{
    public static bool $allowed = true;

    public static bool $canCreate = true;

    public static bool $canUpdate = true;

    public static bool $canDelete = true;

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
        return static::$allowed && static::$canCreate;
    }

    public function update(User $user, Task $task): bool
    {
        return static::$allowed && static::$canUpdate;
    }

    public function delete(User $user, Task $task): bool
    {
        return static::$allowed && static::$canDelete;
    }
}
