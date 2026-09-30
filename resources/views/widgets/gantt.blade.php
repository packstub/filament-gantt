<x-filament-widgets::widget class="ps-gantt-widget">
    <x-filament::section :heading="$this->getHeading()">
        @include('packstub-gantt::gantt')
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
