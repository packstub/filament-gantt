# Actions

The chart speaks Filament actions: the ones you already use on tables (`EditAction`, `ViewAction`, `DeleteAction`, your own `Action::make()`) work on bars, with their forms, modals, slide-overs, confirmations and notifications.

Actions need a component with Filament's action support. `GanttPage`, `GanttResourcePage` and `GanttWidget` have it; for your own Livewire component see [Installation](installation.md#a-chart-in-any-livewire-component).

## A click on a bar

`barAction()` names the action a click runs: an `Action` (with its form), an action class, or the name of one of `barActions()`:

```php
use Filament\Actions\EditAction;

->barAction(EditAction::make()->schema(TaskResource::fields())->slideOver())
```

`TaskResource::fields()` here is your own helper returning the resource's form components; Filament has no such method. `->schema(fn (Schema $schema) => TaskResource::form($schema))` does the same with the resource's `form()`.

The bar's record is the action's record: inject `$record` (or `Task $record`) anywhere, as on a table. It is loaded through the chart's `query()`, so an action can never reach a record the user cannot see on the chart; for such a bar the action is hidden and refused. A drag never counts as a click, even one on a bar that cannot move. Without a click action, a bar with a `url()` follows it; Ctrl/⌘-click opens it in a new tab either way.

## The right-click menu

`barActions()` lists the actions in the menu that opens on a right-click (long-press on touch):

```php
->barActions([
    EditAction::make()->schema(TaskResource::fields()),
    Action::make('done')->icon(Heroicon::OutlinedCheck)->action(fn (Task $record) => $record->update(['progress' => 100])),
    DeleteAction::make(),
])
->barAction('edit')
```

The menu is a keyboard menu too: Shift+F10 on a focused bar opens it under the bar, the arrows move between items, Enter runs one, Escape closes it and returns to the bar.

Your own `hidden()`, `visible()`, `authorize()` and `disabled()` rules keep working, with the record injected. The menu is built once for the whole chart (never per bar), so an action a bar should not offer at all is left out with `Bar::actions()` ([Bars](bars.md#the-bar-shape)).

On a chart page the actions do **not** consult the model policy by themselves (Filament wires that on resource tables only): say what each one needs, `EditAction::make()->authorize('update')`, `DeleteAction::make()->authorize('delete')`, or a `->hidden(fn (Task $record) => ...)` of your own. The page's own access (`canAccess()`, the resource's `viewAny`) is checked as usual.

Once an action has run, the chart reloads its bars (keeping the window and the filters), so an edited bar shows its new dates, a deleted one disappears.

## Create on an empty spot

`createAction()` opens the create form when the user clicks an empty spot of a row. The form opens with the start, the end (one snap unit later) and the row's group already filled in; a form without those fields still gets them on save:

```php
use Filament\Actions\CreateAction;

->createAction(
    CreateAction::make()
        ->label('New task')
        ->schema([
            TextInput::make('title')->required(),
            DatePicker::make('starts_on'),
            DatePicker::make('ends_on'),
            Select::make('project_id')->relationship('project', 'name'),
        ])
        ->slideOver(),
)
```

The model is taken from the chart's query unless you set one with `->model()`. The group goes to the group column, or to the foreign key of a `BelongsTo` group relation; nothing is set for other relations. `mountUsing()`/`fillForm()` and `mutateDataUsing()` you gave the action still run first.

Only what the form has no field for is filled in on save. A field the form does have belongs to the user: a `project_id` Select they cleared stays empty, the clicked row does not put it back.

### Which groups a click may create in

The server checks the group of the clicked row before the form opens, whatever the browser sent (`Gantt::canCreateIn()`): a group listed in `groups()`, one a bar of the chart is in, or, for a `BelongsTo` group, a related record that exists. An unknown key never reaches the record; the create action is hidden and refused for it. To decide yourself (a tenant's own projects, rooms still open for booking):

```php
->creatableIn(fn (string $group) => Project::whereBelongsTo(Filament::getTenant())->whereKey($group)->exists())
```

In a tenant-scoped app, give the form a field for the foreign key with the scoped options (`Select::make('project_id')->relationship('project', 'name')` under the tenant's query) so the value is validated there as well.

Read-only charts get no create click, and the server refuses a create on them.

## Header actions

A chart page is a Filament page: `getHeaderActions()` adds buttons above the chart as usual (a link to the list view, an import, a "New" button that opens a full form).
