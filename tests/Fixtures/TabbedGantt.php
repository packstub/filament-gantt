<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Livewire\Component;
use Packstub\Gantt\Concerns\InteractsWithGantt;
use Packstub\Gantt\Gantt;

/** The chart behind a tab, the way an app's own component includes it on a later render. */
class TabbedGantt extends Component
{
    use InteractsWithGantt;

    public string $tab = 'list';

    public function gantt(Gantt $gantt): Gantt
    {
        return $gantt->query(fn () => Task::query())->start('starts_on')->end('ends_on')->label('title');
    }

    public function render(): string
    {
        return '<div>@if ($tab === \'timeline\') @include(\'packstub-gantt::gantt\') @else <p>The list</p> @endif</div>';
    }
}
