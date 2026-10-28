{{--
    Filament's own select (the component behind Select, searchable or not, single or
    multiple), driven by the component instead of a form: the options come with the
    config or from a renderless search (`$wire.ganttFilterOptions`), a choice flows
    out as a `ps-gantt-select` event. The markup follows Select::toEmbeddedHtml(); the
    messages are Filament's own strings. Rendered inside `x-for="filter in filters"`,
    with `active[filter.name]` holding '' (none), a value, or a list for a multiple.
--}}
<div
    {{--
        Filament 4.16+ and 5.11+ no longer take the four closures below: their select asks
        one scope up for `$callSchemaComponentMethod` (5.11+) or `$getOptionLabel` /
        `$getOptionLabelsForJs` (4.16+), and for its options through the component's
        `callSchemaComponentMethod` with `key` (4.16+), answered by the FilterSelect
        schema (ganttFiltersSchema). Each resolves here, with the filter's name and value.
    --}}
    x-data="{
        $callSchemaComponentMethod: (method, args = {}) => method === 'getOptionsForJs' ? $wire.ganttFilterOptions(filter.name)
            : method === 'getSearchResultsForJs' ? $wire.ganttFilterOptions(filter.name, args.search ?? '')
            : method === 'getOptionLabel' ? $wire.ganttFilterOptionLabel(filter.name, active[filter.name] || null)
            : method === 'getOptionLabelsForJs' ? $wire.ganttFilterOptionLabels(filter.name, active[filter.name] || [])
            : Promise.resolve(null),
        $getOptionLabel: () => $wire.ganttFilterOptionLabel(filter.name, active[filter.name] || null),
        $getOptionLabelsForJs: () => $wire.ganttFilterOptionLabels(filter.name, active[filter.name] || []),
    }"
    style="display: contents"
>
<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('select', 'filament/forms') }}"
    x-data="selectFormComponent({
        canOptionLabelsWrap: false,
        canSelectPlaceholder: true,
        clearButtonLabel: @js(__('filament-forms::components.select.actions.clear.label')),
        errorMessage: null,
        getOptionLabelUsing: async () => await $wire.ganttFilterOptionLabel(filter.name, active[filter.name] || null),
        getOptionLabelsUsing: async () => await $wire.ganttFilterOptionLabels(filter.name, active[filter.name] || []),
        getOptionsUsing: async () => await $wire.ganttFilterOptions(filter.name),
        getSearchResultsUsing: async (search) => await $wire.ganttFilterOptions(filter.name, search),
        hasDynamicOptions: filter.searchable && ! filter.options.length,
        hasDynamicSearchResults: filter.searchable,
        hasInitialNoOptionsMessage: false,
        id: 'ps-gantt-filter-' + filter.name,
        key: 'ganttFilters.' + filter.name,
        initialOptionLabel: null,
        initialOptionLabels: [],
        initialState: filter.multiple ? (active[filter.name] || []) : (active[filter.name] || null),
        isAutofocused: false,
        isDisabled: false,
        isHtmlAllowed: false,
        isMultiple: filter.multiple,
        isReorderable: false,
        isSearchable: filter.searchable,
        livewireId: $wire.__instance?.id ?? null,
        loadingMessage: @js(__('filament-forms::components.select.loading_message')),
        maxItems: null,
        maxItemsMessage: null,
        noOptionsMessage: @js(__('filament-forms::components.select.no_options_message')),
        noSearchResultsMessage: @js(__('filament-forms::components.select.no_search_results_message')),
        options: filter.options,
        optionsLimit: filter.optionsLimit ?? 50,
        placeholder: t.all,
        position: null,
        removeButtonLabel: @js(__('filament-forms::components.select.actions.remove_option.label')),
        searchDebounce: 400,
        searchingMessage: @js(__('filament-forms::components.select.searching_message')),
        searchLabel: @js(__('filament-forms::components.select.search_label')),
        searchPrompt: @js(__('filament-forms::components.select.search_prompt')),
        searchableOptionFields: ['label'],
        state: filter.multiple ? (active[filter.name] || []) : (active[filter.name] || null),
        statePath: 'gantt.' + filter.name,
    })"
    x-init="$watch('state', (value) => $dispatch('ps-gantt-select', value))"
    {{-- the component's state flows in (Reset filters, a value restored); a pick flows out through the watcher, and pickSelect() ignores one that changes nothing --}}
    x-effect="state = filter.multiple ? (active[filter.name] || []) : (active[filter.name] || null)"
    x-on:ps-gantt-select="pickSelect(filter.name, $event.detail)"
    x-on:keydown.esc="select.dropdown.isActive && $event.stopPropagation()"
    x-bind:aria-label="filter.label"
    class="fi-select-input"
>
    <div x-ref="select"></div>
</div>
</div>
