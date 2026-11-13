# Groups

Rows are grouped by a column or a `BelongsTo` relation (a `MorphTo` is refused: two related records of different types may share an id). Each group is a row with a fold button, a colour dot and a count; the bars follow, one row each.

```php
->groupBy('status')                              // a column: the label is the value in headline case (or the enum's HasLabel)
->groupBy('assignee_id')                         // a foreign key: give a label
->groupBy('project', label: 'project.name')      // a BelongsTo relation and a dotted path to its label
->groupBy('project', label: fn (Task $task) => $task->project->code.' · '.$task->project->name)
```

Without `groupBy()` there are no group rows: every bar is a row of its own.

## The groups, in order

By default the groups appear in the order of their first bar, and a group without bars in the current window is not shown. To fix the order and show empty groups, list them:

```php
use Packstub\Gantt\Group;

->groups(fn () => Group::fromModels(Project::query()->orderBy('name')->get(), label: 'name', color: 'color'))

->groups([
    Group::make('todo')->label('To do')->color('gray'),
    Group::make('doing')->label('In progress')->color('amber')->collapsed(),
    Group::make('done')->label('Done')->color('emerald'),
])
```

Bars whose group is not in the list are appended after it, so nothing silently disappears. The keys are the values of the group column, or the related model's key.

| `Group` method | What it does |
| --- | --- |
| `make($key)` | The value of the group column, or the related record's key. |
| `label()` | The row's text (default: the key in headline case). |
| `color()` | The dot next to it: what a bar's `color()` takes ([Bars](bars.md)), or a closure. |
| `collapsed()` | Start folded; the user can unfold it and the chart remembers. |
| `fromModels($records, $label = 'name', $color = null)` | One group per model. |

Folding a group hides its bar rows and leaves a line from its first bar to its last (hover it for the dates); folds are remembered in the browser under the chart's `key()`. With a `createAction()`, a click on the group's own row creates in that group, so a group listed by `groups()` that has no bars yet can get its first one from the chart. A bar dragged onto another group's rows (or carried there with ↑/↓) moves into that group: the column or the foreign key is written with the dates, within the same rules ([Moving to another row](moves.md#to-another-row)).

## Several bars on one line

A task that stops and resumes later, or a second period of the same work: split it into pieces on one line. Each piece is a record of its own, and a nullable column says which line it joins:

```php
Schema::table('tasks', function (Blueprint $table) {
    $table->foreignId('parent_id')->nullable(); // the task whose line this piece joins
});
```

```php
->groupBy('project', label: 'project.name')
->lineBy('parent_id')            // a column, or a BelongsTo relation to the same model ('parent')
```

A record's line is its `parent_id`, or its own key when it has none, so the first piece keeps a null `parent_id` and a record nobody points at is a line of its own, as before. One level deep: a parent has no parent. The pieces of a line share one row inside their group, joined by a dotted line where the work stops and resumes; pieces that overlap stack into lanes and the row grows, so no bar is ever hidden under another. The row is named after the line's own record (its label as its bar shows it), even when that piece is outside the window or filtered out; when the chart's `query()` does not show it at all, after the earliest piece.

Each piece stays a bar: its own dates, actions, rules and `BarMoved` event. What makes them a line:

| You do | What happens |
| --- | --- |
| Drag a line's first piece (the earliest) | The whole line moves, every piece by the same amount, those outside the window too ([A line of pieces](moves.md#a-line-of-pieces)). |
| Drag any other piece, or resize any piece | That piece alone. |
| Drag a piece onto another group's rows, or ↑/↓ | The whole line changes group. |
| Click or drag across a line's row | A new piece on that line: the form gets `parent_id` like it gets the group ([Actions](actions.md#create-on-an-empty-spot)). |
| Click or drag across a group's own row | A task of its own, `parent_id` empty. |

`->movePiecesAlone()` makes the first piece move alone too. `->preventLineOverlap()` refuses a move, a resize or a new piece that would overlap another piece of the same line; the browser stops a drag at the neighbouring piece ([Rules](moves.md#rules)). Pieces that touch, one ending the day before the next starts, never overlap.

A few things to know:

- The column is yours: give it to the model's `$fillable` (like the group's foreign key), or a piece created from a line's row loses its line on save.
- Title, assignee and progress are per piece. Keep them in step yourself if they should be the same.
- Deleting a line's own record leaves its pieces pointing at it: they still share a row, named after the earliest piece. To keep them apart instead, delete them with it (`cascadeOnDelete()`), or point them at the earliest piece in a `deleting` model event. `nullOnDelete()` turns every piece into a line of its own.
- The compact layout (below) ignores lines, its rows are groups; `preventLineOverlap()` still applies there.

## Resource timelines

Rooms and their bookings, machines and their jobs, people and their shifts: every resource is a row and its bars sit side by side. That is the compact layout, `->compact()`: the bars of a group share one row, and overlapping ones stack into lanes, so the row grows as needed.

![A resource timeline on the hour scale: one row per member, the shifts of a member side by side, a line at now](https://raw.githubusercontent.com/packstub/art/main/filament-gantt/docs/shifts.png)

```php
use App\Models\Booking;
use App\Models\Room;
use Packstub\Gantt\Enums\Unit;
use Packstub\Gantt\Gantt;
use Packstub\Gantt\Group;

public function gantt(Gantt $gantt): Gantt
{
    return $gantt
        ->query(fn () => Booking::query()->with(['room', 'guest']))
        ->start('check_in')->end('check_out')
        ->label(fn (Booking $booking) => $booking->guest->name)
        ->color(fn (Booking $booking) => $booking->status->getColor())
        ->groupBy('room', label: 'room.name')
        ->groups(fn () => Group::fromModels(Room::query()->orderBy('floor')->orderBy('name')->get()))
        ->compact()
        ->snapTo(Unit::Day)
        ->canMove(function (Booking $booking, CarbonImmutable $start, CarbonImmutable $end) {
            $taken = Booking::query()
                ->whereKeyNot($booking)
                ->where('room_id', $booking->room_id)
                ->where('check_in', '<', $end)
                ->where('check_out', '>', $start)
                ->exists();

            return $taken ? 'The room is taken on those dates.' : true;
        })
        ->barAction(EditAction::make()->schema(BookingResource::fields()))
        ->createAction(CreateAction::make()->schema(BookingResource::fields()));
}
```

Every room appears, empty or not; a click on an empty spot of a room's row opens the create form with the room and the dates already filled in ([Actions](actions.md)), and a booking dragged onto another room's row moves there ([Moving to another row](moves.md#to-another-row)).

## Rows under the groups

The compact layout has one level: each group is a row. To keep a level above the rows (machines per department, people per project, rooms per floor), group the bars and give them rows of their own with `rowBy()`: the bars of a row sit side by side, overlapping ones stack into lanes, and the row grows as needed.

```php
use App\Models\Machine;
use Packstub\Gantt\Row;

->groupBy('department', label: 'department.name')     // optional: the level above
->rowBy('machine', label: 'machine.name')             // a column, or a BelongsTo to another model
->rows(fn () => Row::fromModels(Machine::query()->orderBy('name')->get(), group: 'department_id'))
```

Without `rows()`, the rows appear in the order of their first bar, inside the groups of their bars. List them to fix the order and show the empty ones, the way `groups()` does for groups:

| `Row` method | What it does |
| --- | --- |
| `make($key)` | The value of the `rowBy()` column, or the related record's key. |
| `label()` | The row's name (default: the key in headline case). |
| `color()` | A dot before the name: what a bar's `color()` takes ([Bars](bars.md)), or a closure. |
| `group()` | The group the row sits in, as `groupBy()` keys it. Without one, the row shows in every group: everyone can work on every project. |
| `fromModels($records, $label = 'name', $color = null, $group = null)` | One row per model; `$group` is an attribute of the model (`department_id`) or a closure. |

A listed row stays on the chart whatever the filters and the search leave, like a listed group; a row met on a bar shows while one of its bars is on the chart.

Bars without a row (jobs nobody picked yet) share one row at the end of their group, called Unassigned, and stack into lanes like any other row. It shows while a bar of the group has no row. Name it with `unassignedRow()` and it stays in every group, empty or not, so a bar can always be dropped there:

```php
->unassignedRow('No machine')                  // always there: drop a bar on it to take it off its machine
->unassignedRow('No machine', always: false)   // only the name: the row shows while a bar has no machine
```

The column has to be nullable for that: taking a bar off its row writes `null`.

| You do | What happens |
| --- | --- |
| Drag a bar onto another row | It moves there: the `rowBy()` column, or the foreign key, is written with its dates ([Moving to another row](moves.md#to-another-row)). |
| Drag it onto the same row in another group | It changes group and keeps its row. Only when the group can be written: `groupBy()` on a column or a `BelongsTo`. |
| ↑/↓ on a focused bar | The previous or next row, across groups the same way. |
| Drag it onto the unassigned row | It is taken off its row: the column is written `null`. |
| Click or drag across a row | A new bar in that row and its group ([Actions](actions.md#create-on-an-empty-spot)); on the unassigned row, a bar without a row. |
| Click a group's own row | A new bar in the group, without a row. |

`->preventRowOverlap()` refuses a move, a resize or a new bar that would overlap another bar of the same row, in any group: a machine busy for one department is busy for all ([Rules](moves.md#rules)). Bars that touch, one ending as the next starts, never overlap; a bar without a row is never checked.

A few things to know:

- The column is yours: give it to the model's `$fillable` (like the group's foreign key), or a bar created on a row loses its row on save.
- `rowBy()` does not go with `compact()` (that layout's rows are the groups) or `lineBy()`; the chart says so when it renders.
- A relation to the chart's own model (parent tasks as rows) is not supported yet: it is the next step of [issue #41](https://github.com/packstub/filament-gantt/issues/41).
