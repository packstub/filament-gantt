<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use Packstub\Gantt\Enums\FiltersLayout;
use Packstub\Gantt\Filter;
use Packstub\Gantt\Tests\Fixtures\PlainGantt;
use Packstub\Gantt\Tests\Fixtures\Project;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskGantt;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-14 10:00:00');
    $this->actingAs(user());
    TaskGantt::$filtersLayout = null;
    TaskGantt::$filters = null;
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    TaskGantt::$filtersLayout = null;
    TaskGantt::$filters = null;
});

/* ------------------------------------------------------------------ the filter types */

it('narrows with a multiple select, per value, ignoring the ones it did not offer', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    $gamma = project('Gamma');
    task('A', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);
    task('B', '2026-10-07', '2026-10-08', ['project_id' => $beta->id]);
    task('C', '2026-10-09', '2026-10-10', ['project_id' => $gamma->id]);

    $filter = Filter::select('project_id')->multiple()->options([$acme->id => 'Acme', $beta->id => 'Beta']);
    $gantt = gantt()->filters([$filter]);

    expect($filter->isMultiple())->toBeTrue()
        ->and($filter->toArray())->toMatchArray(['type' => 'select', 'multiple' => true, 'searchable' => false])
        ->and(array_column($gantt->getBars(['project_id' => [(string) $acme->id, (string) $beta->id]]), 'label'))->toBe(['A', 'B'])
        ->and(array_column($gantt->getBars(['project_id' => [(string) $beta->id, (string) $gamma->id]]), 'label'))->toBe(['B'])
        ->and(array_column($gantt->getBars(['project_id' => (string) $acme->id]), 'label'))->toBe(['A'])
        ->and(array_column($gantt->getBars(['project_id' => [(string) $gamma->id]]), 'label'))->toBe(['A', 'B', 'C'])
        ->and(array_column($gantt->getBars(['project_id' => []]), 'label'))->toBe(['A', 'B', 'C'])
        ->and($filter->state([(string) $beta->id, '999', (string) $beta->id]))->toBe([(string) $beta->id])
        ->and($filter->state(null))->toBe([])
        ->and($filter->emptyState())->toBe([]);

    $received = null;
    $custom = gantt()->filters([Filter::select('project_id')->multiple()->options([$acme->id => 'Acme'])->query(function (Builder $query, array $values) use (&$received) {
        $received = $values;
    })]);
    $custom->getBars(['project_id' => [(string) $acme->id]]);
    expect($received)->toBe([(string) $acme->id]);
});

it('offers a searchable multiple select any keys, since its values come from a search', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    task('A', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);
    task('B', '2026-10-07', '2026-10-08', ['project_id' => $beta->id]);

    $gantt = gantt()->filters([Filter::select('project_id')->multiple()->searchable()->preload(false)]);

    expect(array_column($gantt->getBars(['project_id' => [(string) $beta->id]]), 'label'))->toBe(['B'])
        ->and($gantt->getFilters()[0]->state(['7', '', ['x'], 7]))->toBe(['7']);
});

it('filters with a Yes / No select, handing the query a bool', function () {
    task('Open', '2026-10-05', '2026-10-06', ['progress' => 10]);
    task('Done', '2026-10-07', '2026-10-08', ['progress' => 100]);

    $filter = Filter::boolean('done')->query(fn (Builder $query, bool $value) => $query->where('progress', $value ? '>=' : '<', 100));
    $gantt = gantt()->filters([$filter]);

    expect($filter->isBoolean())->toBeTrue()
        ->and($filter->getOptions())->toBe(['1' => 'Yes', '0' => 'No'])
        ->and($filter->toArray()['options'])->toBe([['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']])
        ->and(array_column($gantt->getBars(['done' => '1']), 'label'))->toBe(['Done'])
        ->and(array_column($gantt->getBars(['done' => '0']), 'label'))->toBe(['Open'])
        ->and(array_column($gantt->getBars(['done' => 'maybe']), 'label'))->toBe(['Open', 'Done'])
        ->and(array_column($gantt->getBars(['done' => '']), 'label'))->toBe(['Open', 'Done'])
        ->and($filter->state('0'))->toBe('0')
        ->and($filter->state('x'))->toBe('')
        ->and($filter->emptyState())->toBe('');

    // without a query: where(<name>, true|false) on the column
    $sql = Filter::boolean('is_done')->apply($q = Task::query(), '1') ?? $q->toSql();
    expect($sql)->toContain('"is_done" = ?')
        ->and($q->getBindings())->toBe([true]);
});

it('filters with a toggle, which needs a query() and gets true', function () {
    task('Mine', '2026-10-05', '2026-10-06', ['assignee_id' => auth()->id()]);
    task('Theirs', '2026-10-07', '2026-10-08', ['assignee_id' => 999]);

    $filter = Filter::toggle('mine')->query(fn (Builder $query, bool $on) => $query->where('assignee_id', auth()->id()));
    $gantt = gantt()->filters([$filter]);

    expect($filter->isToggle())->toBeTrue()
        ->and($filter->getOptions())->toBe([])
        ->and($filter->toArray())->toMatchArray(['type' => 'toggle', 'options' => []])
        ->and(array_column($gantt->getBars(['mine' => true]), 'label'))->toBe(['Mine'])
        ->and(array_column($gantt->getBars(['mine' => '1']), 'label'))->toBe(['Mine'])
        ->and(array_column($gantt->getBars(['mine' => false]), 'label'))->toBe(['Mine', 'Theirs'])
        ->and(array_column($gantt->getBars(['mine' => '0']), 'label'))->toBe(['Mine', 'Theirs'])
        ->and($filter->state('1'))->toBeTrue()
        ->and($filter->state('true'))->toBeTrue()
        ->and($filter->state('0'))->toBeFalse()
        ->and($filter->state(null))->toBeFalse()
        ->and($filter->emptyState())->toBeFalse();

    $bare = Filter::toggle('mine');
    expect(fn () => $bare->apply(Task::query(), true))->toThrow(LogicException::class, 'query()')
        ->and(fn () => $bare->assertUsable())->toThrow(LogicException::class)
        ->and(fn () => gantt()->filters([$bare])->getBars(['mine' => false]))->not->toThrow(LogicException::class);
});

it('tells a toggle without a query() on the first render, not the first time it is turned on', function () {
    TaskGantt::$filters = [Filter::toggle('mine')];

    try {
        Livewire::test(TaskGantt::class);
        $thrown = null;
    } catch (Throwable $thrown) {
        // Blade wraps what a view throws: the cause is the one that matters
        while ($thrown->getPrevious()) {
            $thrown = $thrown->getPrevious();
        }
    }

    expect($thrown)->toBeInstanceOf(LogicException::class)
        ->and($thrown->getMessage())->toContain('Filter [mine] is a toggle');
});

it('reads a date-range state from the URL as real calendar dates or nothing', function () {
    $filter = Filter::dateRange('starts_on');

    expect($filter->state(['from' => '2026-10-01', 'until' => '2026-02-30']))->toBe(['from' => '2026-10-01', 'until' => null])
        ->and($filter->state('garbage'))->toBe(['from' => null, 'until' => null])
        ->and($filter->emptyState())->toBe(['from' => null, 'until' => null])
        ->and($filter->getOptions())->toBe([])
        ->and($filter->isDateRange())->toBeTrue()
        ->and($filter->isSelect())->toBeFalse();
});

it('reads a select state from the URL: one offered option, any key when searchable', function () {
    $plain = Filter::select('status')->options(['open' => 'Open', 'done' => 'Done']);
    $searchable = Filter::select('assignee_id')->searchable();

    expect($plain->state('done'))->toBe('done')
        ->and($plain->state('nope'))->toBe('')
        ->and($plain->state(['open']))->toBe('')
        ->and($plain->state(null))->toBe('')
        ->and($searchable->state('42'))->toBe('42')
        ->and($searchable->state(''))->toBe('')
        ->and($plain->emptyState())->toBe('');
});

it('sends the browser the filter shape it draws from', function () {
    $filter = Filter::select('status')->label('State')->options(['open' => 'Open'])->optionsLimit(10);

    expect($filter->toArray())->toBe([
        'name' => 'status',
        'label' => 'State',
        'type' => 'select',
        'multiple' => false,
        'searchable' => false,
        'optionsLimit' => 10,
        'options' => [['value' => 'open', 'label' => 'Open']],
    ]);
});

it('resolves closure options once per request', function () {
    $calls = 0;
    $filter = Filter::select('status')->options(function () use (&$calls) {
        $calls++;

        return ['open' => 'Open'];
    });

    $filter->getOptions();
    $filter->getOptions();
    $filter->toArray();

    expect($calls)->toBe(1);
});

/* ------------------------------------------------------------------ the fields: Filament's own components */

it('renders every filter as Filament\'s own field, each dispatching its change to the browser', function () {
    project('Acme');
    TaskGantt::$filters = [
        Filter::select('project_id')->options(fn () => Project::query()->pluck('name', 'id')->all()),
        Filter::select('owner')->multiple()->searchable()->options(['a' => 'A']),
        Filter::boolean('done')->query(fn ($q, $v) => $q),
        Filter::toggle('mine')->query(fn ($q) => $q),
        Filter::dateRange('starts_on')->label('Starts'),
    ];

    $component = Livewire::test(TaskGantt::class);
    $html = $component->html();

    // a native select for one option, Filament's select for several, a toggle, two date pickers in a fieldset,
    // each watching its state path and dispatching the plugin's event with the filter's name (and the edge of a range)
    expect($html)->toContain('fi-fo-select-native')
        ->and($html)->toContain('fi-fo-select-wrp')
        ->and($html)->toContain('fi-fo-toggle')
        ->and(substr_count($html, 'fi-fo-date-time-picker-trigger'))->toBeGreaterThanOrEqual(4)
        ->and($html)->toContain('Starts')
        ->and($html)->toContain('$wire.watch(\'ganttFilters.project_id\'')
        ->and($html)->toContain('$wire.watch(\'ganttFilters.mine\'')
        ->and($html)->toContain('$wire.watch(\'ganttFilters.starts_on.from\'')
        ->and($html)->toContain('$wire.watch(\'ganttFilters.starts_on.until\'')
        ->and($html)->toContain('ps-gantt-filter\\u0027, { name: \\u0027project_id\\u0027')
        ->and($html)->toContain('$wire.watch(\'ganttWindow.start\'')
        ->and($html)->toContain('$wire.watch(\'ganttWindow.end\'')
        ->and($html)->toContain('ps-gantt-range\\u0027, { edge: \\u0027end\\u0027');
});

it('answers a searchable select\'s search and labels through Filament\'s own select, by its schema key', function () {
    $acme = project('Acme');
    $beta = project('Beta');
    TaskGantt::$filters = [Filter::select('project_id')->multiple()->searchable()->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id')->all())];

    Livewire::test(TaskGantt::class)
        ->call('callSchemaComponentMethod', 'ganttFiltersForm.project_id', 'getSearchResultsForJs', ['search' => 'bet'])
        ->assertReturned(fn ($options) => collect($options)->pluck('label')->all() === ['Beta'] && (string) collect($options)->first()['value'] === (string) $beta->id)
        ->call('callSchemaComponentMethod', 'ganttFiltersForm.project_id', 'getOptionsForJs')
        ->assertReturned(fn ($options) => collect($options)->pluck('label')->all() === ['Acme', 'Beta'])
        ->set('ganttFilters.project_id', [(string) $beta->id, '999', (string) $acme->id])
        ->call('callSchemaComponentMethod', 'ganttFiltersForm.project_id', 'getOptionLabelsForJs')
        ->assertReturned(fn ($labels) => collect($labels)->pluck('label', 'value')->all() == [$beta->id => 'Beta', $acme->id => 'Acme'])
        ->call('callSchemaComponentMethod', 'ganttFiltersForm.nope', 'getOptionsForJs')
        ->assertReturned(null);
});

/* ------------------------------------------------------------------ where the filters are shown */

it('takes the filters layout from the definition, else from the config, and refuses a value that is no layout', function () {
    expect(gantt()->getFiltersLayout())->toBe(FiltersLayout::Dropdown)
        ->and(gantt()->filtersLayout(FiltersLayout::Toolbar)->getFiltersLayout())->toBe(FiltersLayout::Toolbar)
        ->and(gantt()->filtersLayout('above')->getFiltersLayout())->toBe(FiltersLayout::AboveContent);

    config()->set('filament-gantt.filters_layout', 'toolbar');
    expect(gantt()->getFiltersLayout())->toBe(FiltersLayout::Toolbar)
        ->and(gantt()->filtersLayout(FiltersLayout::Dropdown)->getFiltersLayout())->toBe(FiltersLayout::Dropdown);

    config()->set('filament-gantt.filters_layout', null);
    expect(gantt()->getFiltersLayout())->toBe(FiltersLayout::Dropdown);

    config()->set('filament-gantt.filters_layout', 'sidebar');
    expect(fn () => gantt()->getFiltersLayout())->toThrow(InvalidArgumentException::class, 'filament-gantt.filters_layout [sidebar]')
        ->and(fn () => gantt()->filtersLayout('sidebar')->getFiltersLayout())->toThrow(InvalidArgumentException::class);
});

it('renders the filters behind a funnel by default, inline in the toolbar, or on a row above the chart', function () {
    $dropdown = Livewire::test(TaskGantt::class);
    $dropdown->assertSee('ps-gantt-toolbar-start', false)
        ->assertSee('ps-gantt-toolbar-end', false)
        ->assertSee('ps-gantt-filters-trigger', false)
        ->assertSee('ps-gantt-filters-panel', false)
        ->assertSee('ps-gantt-footer', false)
        ->assertSee('ps-gantt-menu fi-dropdown-panel', false)
        ->assertSee('fi-dropdown-list-item', false)
        ->assertSee('ps-gantt-search', false)
        ->assertDontSee('ps-gantt-filters-inline', false)
        ->assertDontSee('ps-gantt-filters-above', false);
    expect($dropdown->instance()->getGanttConfig())->toMatchArray(['filtersLayout' => 'dropdown', 'searchable' => true]);

    TaskGantt::$filtersLayout = FiltersLayout::Toolbar;
    Livewire::test(TaskGantt::class)
        ->assertSee('ps-gantt-filters-inline', false)
        ->assertDontSee('ps-gantt-filters-trigger', false)
        ->assertDontSee('ps-gantt-filters-above', false);

    TaskGantt::$filtersLayout = 'above';
    Livewire::test(TaskGantt::class)
        ->assertSee('ps-gantt-filters-above', false)
        ->assertDontSee('ps-gantt-filters-trigger', false)
        ->assertDontSee('ps-gantt-filters-inline', false);
});

it('renders no funnel and no search box on a chart without filters or searchable attributes', function () {
    Livewire::test(PlainGantt::class)
        ->assertSee('ps-gantt-toolbar', false)
        ->assertDontSee('ps-gantt-filters-trigger', false)
        ->assertDontSee('ps-gantt-search', false);
});

/* ------------------------------------------------------------------ the search */

it('searches the searchable attributes, a dotted path through the relation, with the wildcards escaped', function () {
    $acme = project('Acme Corp');
    task('Write docs', '2026-10-05', '2026-10-06');
    task('Ship 100% of it', '2026-10-07', '2026-10-08', ['project_id' => $acme->id]);
    task('Under_score', '2026-10-09', '2026-10-10');

    $gantt = gantt()->searchable(['title', 'project.name', 42]);

    expect($gantt->getSearchable())->toBe(['title', 'project.name'])
        ->and($gantt->isSearchable())->toBeTrue()
        ->and(gantt()->isSearchable())->toBeFalse()
        ->and(array_column($gantt->getBars([], null, null, 'docs'), 'label'))->toBe(['Write docs'])
        ->and(array_column($gantt->getBars([], null, null, 'DOCS'), 'label'))->toBe(['Write docs'])
        ->and(array_column($gantt->getBars([], null, null, 'acme'), 'label'))->toBe(['Ship 100% of it'])
        ->and(array_column($gantt->getBars([], null, null, '100%'), 'label'))->toBe(['Ship 100% of it'])
        ->and(array_column($gantt->getBars([], null, null, '%'), 'label'))->toBe(['Ship 100% of it'])
        ->and(array_column($gantt->getBars([], null, null, '_'), 'label'))->toBe(['Under_score'])
        ->and(array_column($gantt->getBars([], null, null, '  '), 'label'))->toBe(['Write docs', 'Ship 100% of it', 'Under_score'])
        ->and(array_column($gantt->getBars([], null, null, 'nothing here'), 'label'))->toBe([])
        // without searchable() a term changes nothing
        ->and(array_column(gantt()->getBars([], null, null, 'docs'), 'label'))->toBe(['Write docs', 'Ship 100% of it', 'Under_score'])
        // the span Fit loads follows the search too
        ->and($gantt->getSpan([], 'docs'))->toBe(['start' => '2026-10-05', 'end' => '2026-10-06'])
        ->and($gantt->getState([], null, null, false, 'acme')['total'])->toBe(1);
});

it('refreshes with the search as a trailing argument, read as a string or nothing', function () {
    $acme = project('Acme');
    task('Write docs', '2026-10-05', '2026-10-06', ['project_id' => $acme->id]);
    task('Build', '2026-10-07', '2026-10-08');

    Livewire::test(TaskGantt::class)
        ->call('ganttRefresh', [], null, null, false, false, 'docs')
        ->assertReturned(fn ($result) => array_column($result['bars'], 'label') === ['Write docs'])
        ->call('ganttRefresh', ['project_id' => (string) $acme->id], null, null, false, false, 'build')
        ->assertReturned(fn ($result) => $result['bars'] === [])
        ->call('ganttRefresh', [], null, null, false, false, ['docs'])
        ->assertReturned(fn ($result) => count($result['bars']) === 2)
        ->call('ganttRefresh', [])
        ->assertReturned(fn ($result) => count($result['bars']) === 2)
        ->call('ganttSpan', [], 'build')
        ->assertReturned(['start' => '2026-10-07', 'end' => '2026-10-08'])
        ->call('ganttSpan', [], ['x'])
        ->assertReturned(['start' => '2026-10-05', 'end' => '2026-10-08']);
});

/* ------------------------------------------------------------------ bounds and the schema route (Filament 4.16+ / 5.11+) */

it('caps the values a searchable multiple filter, a label lookup and a search take', function () {
    $lookups = 0;
    TaskGantt::$filters = [
        Filter::select('owner')->multiple()->searchable()->optionsLimit(5)
            ->options(['a' => 'A'])
            ->getOptionLabelUsing(function (string $value) use (&$lookups) {
                $lookups++;

                return "L{$value}";
            })
            ->getSearchResultsUsing(fn (string $search) => collect(['s' => mb_strlen($search)])),
    ];

    $filter = TaskGantt::$filters[0];
    $applied = null;
    $filter->query(function ($query, array $values) use (&$applied) {
        $applied = $values;
    });
    $filter->apply(Task::query(), array_map('strval', range(1, 50)));

    expect($applied)->toHaveCount(5)->and($applied)->toBe(['1', '2', '3', '4', '5']);

    $filter->apply(Task::query(), [str_repeat('x', 256), 'ok']);
    expect($applied)->toBe(['ok']);

    Livewire::test(TaskGantt::class)
        ->set('ganttFilters.owner', array_merge(array_map('strval', range(1, 40)), ['1', '1']))
        ->call('callSchemaComponentMethod', 'ganttFiltersForm.owner', 'getOptionLabelsForJs')
        ->assertReturned(fn ($labels) => collect($labels)->pluck('label')->all() === ['L1', 'L2', 'L3', 'L4', 'L5'])
        ->call('callSchemaComponentMethod', 'ganttFiltersForm.owner', 'getSearchResultsForJs', ['search' => str_repeat('q', 500)])
        ->assertReturned(fn ($options) => array_map('strval', collect($options)->pluck('label')->all()) === ['200']);

    // five labels looked up per call (Filament reads the labels twice while answering)
    expect($lookups)->toBeLessThanOrEqual(10);
});
