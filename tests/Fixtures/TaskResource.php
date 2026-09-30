<?php

namespace Packstub\Gantt\Tests\Fixtures;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\Gantt\Tests\Fixtures\TaskResource\Pages\ListTasks;
use Packstub\Gantt\Tests\Fixtures\TaskResource\Pages\TasksGantt;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $slug = 'tasks';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            DatePicker::make('starts_on'),
            DatePicker::make('ends_on'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'gantt' => TasksGantt::route('/gantt'),
        ];
    }
}
