<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Filament\Support\Contracts\HasLabel;

enum TaskStatus: string implements HasLabel
{
    case Todo = 'todo';
    case Done = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::Done => 'Done',
        };
    }
}
