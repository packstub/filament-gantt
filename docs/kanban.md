# Kanban + Gantt on the same records

[Filament Kanban](https://packstub.dev/docs/filament-kanban) and Filament Gantt share one design: the browser owns the view, Livewire answers renderless calls, and every rule is re-checked on the server. They also share the same inputs, so the same resource can offer a board for the flow and a timeline for the schedule.

![The same tasks on a Kanban board, one page next to the timeline](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/kanban.png)

## Two pages on one resource

```php
public static function getPages(): array
{
    return [
        'index' => ListTasks::route('/'),
        'board' => TaskBoard::route('/board'),
        'gantt' => TasksGantt::route('/gantt'),
    ];
}
```

Give both the same `query()` and the same actions, from one place:

```php
class TaskResource extends Resource
{
    public static function timelineQuery(): Builder
    {
        return Task::query()->whereBelongsTo(Filament::getTenant())->with(['project', 'assignee']);
    }

    /** @return array<Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('title')->required(),
            Select::make('status')->options(TaskStatus::class),
            DatePicker::make('starts_on'),
            DatePicker::make('ends_on'),
        ];
    }
}

// TaskBoard.php
->query(fn () => TaskResource::timelineQuery())
->columns(TaskStatus::class)
->cardActions([EditAction::make()->schema(TaskResource::fields())])

// TasksGantt.php
->query(fn () => TaskResource::timelineQuery())
->groupBy('project', label: 'project.name')
->barAction(EditAction::make()->schema(TaskResource::fields()))
```

Link them from each other with a header action:

```php
protected function getHeaderActions(): array
{
    return [Action::make('board')->url(TaskResource::getUrl('board'))->icon(Heroicon::OutlinedViewColumns)];
}
```

## Keeping the two in step

A move on one view is an event the other can answer:

```php
Event::listen(function (\Packstub\Kanban\Events\CardMoved $event) {
    if ($event->to === 'done') {
        $event->record->update(['progress' => 100, 'ends_on' => today()]);
    }
});

Event::listen(function (\Packstub\Gantt\Events\BarMoved $event) {
    if ($event->start->isFuture() && $event->record->status === 'doing') {
        $event->record->update(['status' => 'todo']);
    }
});
```

Both pages refresh after their own actions; to refresh one after a change made elsewhere on the page, dispatch `packstub-kanban-refresh` or `packstub-gantt-refresh`, or list your own events with `refreshOn()`.
