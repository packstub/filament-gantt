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

The bar's record is the action's record: inject `$record` (or `Task $record`) anywhere, as on a table. It is loaded through the chart's `query()`, so an action can never reach a record the user cannot see on the chart; for such a bar the action is hidden and refused. A drag never counts as a click, even one on a bar that cannot move, nor one that only went up or down. Enter or Space on a focused bar is a click. Without a click action, a bar with a `url()` follows it; Ctrl/⌘-click opens it in a new tab either way.

## The right-click menu

`barActions()` lists the actions in the menu that opens on a right-click (on touch: press and hold the bar, then lift):

```php
->barActions([
    EditAction::make()->schema(TaskResource::fields()),
    Action::make('done')->icon(Heroicon::OutlinedCheck)->action(fn (Task $record) => $record->update(['progress' => 100])),
    DeleteAction::make(),
])
->barAction('edit')
```

The menu is a keyboard menu too: the context-menu key on a focused bar (Shift+F10 or the Menu key where the browser has one, Windows and Linux; on macOS a right-click on the focused bar) opens it under the bar, the arrows move between items, Home and End go to the first and the last, Enter runs one, Escape closes it and returns to the bar.

### Which actions a bar offers

Each bar offers the actions the user may run on **its** record. When the chart loads, every action is asked about every bar's record: your own `hidden()`, `visible()` and `authorize()` rules, with the record injected, and Filament's default authorization. An action a user may not run is not in that bar's menu, and a click on the bar does not open it. `Bar::actions()` narrows a bar's menu further ([Bars](bars.md#the-bar-shape)), and the server holds to it: a request for an action the bar does not offer is refused, whatever the browser sends.

On a `GanttResourcePage`, Filament authorizes `EditAction`, `ViewAction`, `DeleteAction` and `CreateAction` with the resource's policy, as on the resource's own pages: a user without `update` sees no Edit on any bar. On a `GanttPage`, a widget or your own component there is no resource to ask: say what each action needs, `EditAction::make()->authorize('update')`, `DeleteAction::make()->authorize('delete')`, or a `->hidden(fn (Task $record) => ...)` of your own. The page's own access (`canAccess()`, the resource's `viewAny`) is checked as usual, and moves follow the policy on their own ([Moving and resizing](moves.md#who-may-move)).

A label, icon or colour closure that needs the record (`fn (Task $record) => ...`) is read without one for the menu, and falls back to the action's name.

Once an action has run, the chart reloads its bars (keeping the window and the filters), so an edited bar shows its new dates, a deleted one disappears.

## Create on an empty spot

`createAction()` opens the create form when the user clicks an empty spot of a row, a group's own row included (a group with no bars yet has nothing else to click). The form opens with the start, the end (one snap unit later) and the row's group already filled in; a form without those fields still gets them on save:

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

The server checks the group of the clicked row before the form opens, whatever the browser sent (`Gantt::canCreateIn()`): a group listed in `groups()` or one a bar of the chart is in, the only rows there are to click, plus the ungrouped row. Anything else is refused, a related record that exists but has no row on the chart included (a project the chart's `query()` does not show, say): the key never reaches the record and the create action is hidden for it. To decide yourself (a tenant's own projects, rooms still open for booking):

```php
->creatableIn(fn (string $group) => Project::whereBelongsTo(Filament::getTenant())->whereKey($group)->exists())
```

In a tenant-scoped app, give the form a field for the foreign key with the scoped options (`Select::make('project_id')->relationship('project', 'name')` under the tenant's query) so the value is validated there as well.

The create click is offered only to who may run the action: its own `hidden()` and `authorize()`, and on a `GanttResourcePage` the resource's `canCreate()` (the policy's `create`). Read-only charts get no create click, and the server refuses a create on them.

## Header actions

A chart page is a Filament page: `getHeaderActions()` adds buttons above the chart as usual (a link to the list view, an import, a "New" button that opens a full form).
