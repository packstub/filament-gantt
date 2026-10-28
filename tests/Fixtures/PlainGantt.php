<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Livewire\Component;
use Packstub\Gantt\Concerns\InteractsWithGantt;
use Packstub\Gantt\Gantt;

/** A chart on a Livewire component without Filament's action system. */
class PlainGantt extends Component implements HasSchemas
{
    use InteractsWithGantt;
    use InteractsWithSchemas;

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
