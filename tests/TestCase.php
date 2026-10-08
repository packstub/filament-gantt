<?php

namespace Packstub\Gantt\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\Gantt\GanttServiceProvider;
use Packstub\Gantt\Tests\Fixtures\AdminPanelProvider;
use Packstub\Gantt\Tests\Fixtures\Task;
use Packstub\Gantt\Tests\Fixtures\TaskPolicy;
use Packstub\Gantt\Tests\Fixtures\User;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        TaskPolicy::$allowed = true;
        TaskPolicy::$canCreate = true;
        TaskPolicy::$canUpdate = true;
        TaskPolicy::$canDelete = true;
        Gate::policy(Task::class, TaskPolicy::class);

        // A real server (CI's MySQL and PostgreSQL jobs) keeps its tables between tests.
        if (config('database.default') !== 'sqlite') {
            Schema::dropAllTables();
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('color')->nullable();
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->nullable();
            $table->foreignId('assignee_id')->nullable();
            $table->string('title');
            $table->string('status')->default('todo');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('progress')->default(0);
            $table->json('depends_on')->nullable();
        });

        Schema::create('task_dependencies', function (Blueprint $table): void {
            $table->foreignId('task_id');
            $table->foreignId('predecessor_id');
        });

        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('room')->nullable();
            $table->string('title');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            // Real apps discover filament/* before livewire; keep that order.
            LivewireServiceProvider::class,
            GanttServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // SQLite in memory by default. DB_CONNECTION=mysql or pgsql (with DB_HOST,
        // DB_DATABASE, DB_USERNAME…) runs the suite on a real server, the way CI does:
        // MySQL's strict mode and PostgreSQL refuse SQL that SQLite lets through.
        $connection = (string) env('DB_CONNECTION', 'sqlite');
        $app['config']->set('database.default', $connection);

        if ($connection === 'sqlite') {
            $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        }
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:2fl+Ktv6fZ7c7ZQfF1Zt6Q0Wd9jz5bJ6rKq8nX0m3Yk=');
        $app['config']->set('app.url', 'http://localhost');
    }
}
