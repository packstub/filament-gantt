{{--
    Filament's own select (the component behind Select, searchable or not), driven by
    the chart instead of a form: the options come with the chart or from a renderless
    search (`ganttFilterOptions`), a choice flows out as a `gantt-select` event. The
    markup follows Select::toEmbeddedHtml(); the messages are Filament's own strings.
    Rendered inside the chart's `x-for="filter in filters"`.
--}}
<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('select', 'filament/forms') }}"
    x-data="selectFormComponent({
        canOptionLabelsWrap: false,
        canSelectPlaceholder: true,
        clearButtonLabel: @js(__('filament-forms::components.select.actions.clear.label')),
        getOptionLabelUsing: async () => await $wire.ganttFilterOptionLabel(filter.name, active[filter.name] || null),
        getOptionLabelsUsing: async () => [],
        getOptionsUsing: async () => await $wire.ganttFilterOptions(filter.name),
        getSearchResultsUsing: async (search) => await $wire.ganttFilterOptions(filter.name, search),
        hasDynamicOptions: filter.searchable && ! filter.options.length,
        hasDynamicSearchResults: filter.searchable,
        hasInitialNoOptionsMessage: false,
        id: 'ps-gantt-filter-' + filter.name,
        initialOptionLabel: null,
        initialOptionLabels: [],
        initialState: active[filter.name] || null,
        isAutofocused: false,
        isDisabled: false,
        isHtmlAllowed: false,
        isMultiple: false,
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
        state: active[filter.name] || null,
        statePath: 'gantt.' + filter.name,
    })"
    x-init="$watch('state', (value) => $dispatch('gantt-select', value))"
    x-on:gantt-select="pickSelect(filter.name, $event.detail)"
    x-on:keydown.esc="select.dropdown.isActive && $event.stopPropagation()"
    x-bind:aria-label="filter.label"
    class="fi-select-input"
>
    <div x-ref="select"></div>
</div>
