<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Livewire\Component;
use Packstub\Gantt\Concerns\InteractsWithGantt;
use Packstub\Gantt\Gantt;

/** A chart on a Livewire component without Filament's action system. */
class PlainGantt extends Component
{
    use InteractsWithGantt;

    public function gantt(Gantt $gantt): Gantt
    {
        return $gantt
            ->query(fn () => Task::query())
            ->start('starts_on')->end('ends_on')
            ->barActions([EditAction::make()])
            ->createAction(CreateAction::make());
    }

    public function render(): string
    {
        return '<div>@include(\'packstub-gantt::gantt\')</div>';
    }
}
