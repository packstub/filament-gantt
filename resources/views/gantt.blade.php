@php
    use Filament\Support\Facades\FilamentAsset;

    $config = $this->getGanttConfig();
@endphp

{{--
    The browser owns the chart: bars are drawn from JSON by Alpine and moved
    optimistically; Livewire only answers renderless calls. wire:ignore keeps a
    page re-render (an action's modal, say) from resetting it, and such a render
    carries no bars at all (see getGanttConfig()): the browser has its own.
--}}
<div
    wire:ignore
    x-load
    x-load-src="{{ FilamentAsset::getAlpineComponentSrc('gantt', 'packstub/filament-gantt') }}"
    x-load-css="[@js(FilamentAsset::getStyleHref('gantt', 'packstub/filament-gantt'))]"
    x-data="packstubGantt(@js($config))"
    x-on:keydown.escape.window="onEscape()"
    x-on:pointerdown.capture="swallowClick = false"
    x-on:packstub-gantt-refresh.window="refresh(true)"
    @foreach ($config['refreshOn'] as $event)
        x-on:{{ $event }}.window="refresh(true)"
    @endforeach
    class="ps-gantt"
    :class="{ 'ps-gantt-is-dragging': dragging, 'ps-gantt-is-readonly': readOnly, 'ps-gantt-is-compact': compact }"
    :style="height ? '--ps-gantt-height:' + height : null"
>
    {{-- The toolbar is Filament's own components (tabs, button, input wrapper, input, select), so it follows the panel's theme. --}}
    <div class="ps-gantt-toolbar">
        <x-filament::tabs x-bind:aria-label="t.zoom">
            <template x-for="s in scales" :key="s">
                <x-filament::tabs.item alpine-active="scale === s" x-on:click="setScale(s)">
                    <span x-text="t[s]"></span>
                </x-filament::tabs.item>
            </template>
        </x-filament::tabs>

        <x-filament::button color="gray" x-on:click="scrollToToday()">
            <span x-text="t.today"></span>
        </x-filament::button>

        <div class="ps-gantt-range" role="group" x-bind:aria-label="t.range">
            <x-filament::input.wrapper>
                <x-slot name="prefix"><span x-text="t.from"></span></x-slot>
                @include('packstub-gantt::partials.date-picker', ['model' => 'range.start', 'pick' => "pickRange('start', \$event.detail)", 'label' => 't.from'])
            </x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-slot name="prefix"><span x-text="t.until"></span></x-slot>
                @include('packstub-gantt::partials.date-picker', ['model' => 'range.end', 'pick' => "pickRange('end', \$event.detail)", 'label' => 't.until'])
            </x-filament::input.wrapper>
        </div>

        <template x-for="filter in filters" :key="filter.name">
            <div class="ps-gantt-filter" :class="{ 'ps-gantt-filter-on': filter.type === 'dateRange' ? (active[filter.name].from || active[filter.name].until) : !! active[filter.name] }">
                <template x-if="filter.type === 'dateRange'">
                    <div class="ps-gantt-range">
                        <x-filament::input.wrapper>
                            <x-slot name="prefix"><span x-text="filter.label"></span></x-slot>
                            @include('packstub-gantt::partials.date-picker', ['model' => 'active[filter.name].from', 'pick' => "pickFilter(filter.name, 'from', \$event.detail)", 'label' => "filter.label + ' ' + t.from"])
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper>
                            <x-slot name="prefix">–</x-slot>
                            @include('packstub-gantt::partials.date-picker', ['model' => 'active[filter.name].until', 'pick' => "pickFilter(filter.name, 'until', \$event.detail)", 'label' => "filter.label + ' ' + t.until"])
                        </x-filament::input.wrapper>
                    </div>
                </template>
                <template x-if="filter.type !== 'dateRange'">
                    <x-filament::input.wrapper>
                        <x-slot name="prefix"><span x-text="filter.label"></span></x-slot>
                        @include('packstub-gantt::partials.select')
                    </x-filament::input.wrapper>
                </template>
            </div>
        </template>

        <div class="ps-gantt-spacer"></div>

        <span class="ps-gantt-count" :class="{ 'ps-gantt-count-truncated': total > bars.length }" x-text="countText()" :title="total > bars.length ? t.truncated_hint : null"></span>
    </div>

    <div class="ps-gantt-scroll" x-ref="scroll" x-on:scroll.passive="onScroll()">
        <div class="ps-gantt-head">
            <div class="ps-gantt-corner"></div>
            <div class="ps-gantt-axis" :style="'width:' + view.width + 'px'">
                <div class="ps-gantt-axis-row ps-gantt-axis-top">
                    <template x-for="c in visibleTop" :key="c.key">
                        <div class="ps-gantt-cell" :style="cell(c)"><span x-text="c.label"></span></div>
                    </template>
                </div>
                <div class="ps-gantt-axis-row ps-gantt-axis-bottom">
                    <template x-for="c in visibleColumns" :key="c.key">
                        <div class="ps-gantt-cell" :class="{ 'ps-gantt-cell-weekend': c.weekend, 'ps-gantt-cell-today': c.today }" :style="cell(c)"><span x-text="c.label"></span></div>
                    </template>
                </div>
            </div>
        </div>

        <div class="ps-gantt-body" x-ref="body">
            <div class="ps-gantt-grid" :style="'width:' + view.width + 'px'">
                <template x-for="s in visibleShades" :key="s.key">
                    <div class="ps-gantt-shade" :style="cell(s)"></div>
                </template>
                <template x-for="c in visibleColumns" :key="c.key">
                    <div class="ps-gantt-line" :style="{ 'inset-inline-start': c.x + 'px' }"></div>
                </template>
                <div class="ps-gantt-today-line" x-show="view.todayX !== null" :style="{ 'inset-inline-start': view.todayX + 'px' }"></div>
            </div>

            {{-- Dependency arrows, one SVG over the whole body, mirrored by CSS in RTL. Two paths holding every
                 arrow of their kind: an x-for cannot run inside an svg (its <template> is not one there). --}}
            <svg class="ps-gantt-arrows" aria-hidden="true" x-show="arrowPaths.length" :style="{ width: view.width + 'px', height: bodyHeight + 'px' }" :viewBox="'0 0 ' + view.width + ' ' + bodyHeight">
                <path :d="arrowD(false)"></path>
                <path class="ps-gantt-arrow-late" :d="arrowD(true)"></path>
            </svg>

            <div class="ps-gantt-pad" :style="'height:' + slice.top + 'px'"></div>

            <template x-for="row in visibleRows" :key="row.key">
                <div class="ps-gantt-row" :class="{ ['ps-gantt-row-' + row.type]: true, 'ps-gantt-row-target': target !== null && row.group.key === target }" :style="'height:' + row.height + 'px'">
                    <div class="ps-gantt-label">
                        <template x-if="row.type === 'group'">
                            <button type="button" class="ps-gantt-fold" x-on:click="toggle(row.group.key)" :title="row.collapsed ? t.expand : t.collapse" :aria-expanded="! row.collapsed">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" :class="{ 'ps-gantt-fold-closed': row.collapsed }"><path d="m6 8 4 4 4-4"/></svg>
                            </button>
                        </template>
                        <span class="ps-gantt-dot" x-show="row.type !== 'bar' && row.group.color" :style="{ background: tone(row.group.color) }"></span>
                        <span class="ps-gantt-label-text" :class="{ 'ps-gantt-label-group': row.type !== 'bar' }" x-text="row.label" :title="row.label"></span>
                        <span class="ps-gantt-label-count" x-show="row.type !== 'bar'" x-text="row.group.count"></span>
                    </div>

                    <div
                        class="ps-gantt-canvas"
                        :style="'width:' + view.width + 'px'"
                        :class="{ 'ps-gantt-canvas-creatable': creatable(row) }"
                        :title="creatable(row) ? t.create_here : null"
                        x-on:click="createAt($event, row)"
                    >
                        <template x-for="bar in row.bars" :key="bar.id">
                            <div
                                class="ps-gantt-bar"
                                :class="{
                                    'ps-gantt-bar-milestone': bar.milestone,
                                    'ps-gantt-bar-pending': bar.src._pending,
                                    'ps-gantt-bar-flash': flash === bar.id,
                                    'ps-gantt-bar-locked': ! bar.movable,
                                    'ps-gantt-bar-clickable': !! (barAction || bar.url),
                                    'ps-gantt-bar-narrow': narrow(bar),
                                    'ps-gantt-bar-outside': labelOutside(bar),
                                    'ps-gantt-bar-picked': pointer?.picked && pointer.bar.id === bar.id,
                                    'ps-gantt-bar-carried': pointer?.dy && pointer.bar.id === bar.id,
                                }"
                                :style="barStyle(bar)"
                                :title="tooltip(bar)"
                                :aria-label="describe(bar)"
                                :data-id="bar.id"
                                role="button"
                                tabindex="0"
                                x-on:pointerdown="down($event, bar)"
                                x-on:pointermove="drag($event)"
                                x-on:pointerup="up($event)"
                                x-on:pointercancel="cancel($event)"
                                x-on:lostpointercapture="cancel($event)"
                                x-on:touchmove="pointer?.picked && $event.cancelable && $event.preventDefault()"
                                x-on:click.stop
                                x-on:keydown.enter.prevent="open(bar, $event)"
                                x-on:keydown.space.prevent="open(bar, $event)"
                                x-on:keydown.left.prevent="nudge($event, bar)"
                                x-on:keydown.right.prevent="nudge($event, bar)"
                                x-on:keydown.up.prevent="nudge($event, bar)"
                                x-on:keydown.down.prevent="nudge($event, bar)"
                                x-on:contextmenu.prevent.stop="openMenu($event, bar)"
                            >
                                <span class="ps-gantt-progress" x-show="bar.progress !== null && bar.progress !== undefined && ! bar.milestone" :style="{ width: (bar.progress || 0) + '%' }"></span>
                                <span class="ps-gantt-bar-label" x-text="bar.label"></span>
                                <span class="ps-gantt-handle ps-gantt-handle-start" data-edge="start" x-show="bar.resizable && ! narrow(bar)"></span>
                                <span class="ps-gantt-handle ps-gantt-handle-end" data-edge="end" x-show="bar.resizable && ! narrow(bar)"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <div class="ps-gantt-pad" :style="'height:' + slice.bottom + 'px'"></div>
        </div>

        <div class="ps-gantt-empty" x-show="! bars.length" x-text="t.empty"></div>
    </div>

    <div class="ps-gantt-hint" x-show="hint" x-cloak x-text="hint"></div>

    {{-- What a keyboard move did, or why it was refused, for screen readers --}}
    <div class="ps-gantt-sr" role="status" aria-live="polite" x-text="live"></div>

    {{-- x-show and :style share the style attribute: object syntax keeps the display x-show sets --}}
    <div
        class="ps-gantt-menu"
        x-ref="menu"
        x-show="menu"
        x-cloak
        :style="menu ? { left: menu.x + 'px', top: menu.y + 'px' } : {}"
        x-on:click.outside="closeMenu()"
        x-on:keydown.down.prevent="menuMove(1)"
        x-on:keydown.up.prevent="menuMove(-1)"
        x-on:keydown.home.prevent="menuMove(-Infinity)"
        x-on:keydown.end.prevent="menuMove(Infinity)"
        x-on:keydown.tab="closeMenu()"
        role="menu"
        :aria-label="t.bar_menu"
    >
        <template x-for="action in (menu ? actionsFor(menu.bar) : [])" :key="action.name">
            <button type="button" class="ps-gantt-menu-item" :class="action.color && 'ps-gantt-menu-item-' + action.color" role="menuitem" x-on:click="runAction(action.name, menu.bar)">
                <span class="ps-gantt-menu-icon" x-show="action.icon" x-html="action.icon"></span>
                <span x-text="action.label"></span>
            </button>
        </template>
    </div>
</div>
