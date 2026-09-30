<?php

namespace Packstub\Gantt;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class GanttServiceProvider extends PackageServiceProvider
{
    public static string $name = 'packstub-gantt';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('filament-gantt')
            ->hasViews(static::$name)
            ->hasTranslations();
    }

    public function packageBooted(): void
    {
        // Loaded by the chart itself (x-load / x-load-css), so pages without one pay nothing.
        FilamentAsset::register([
            AlpineComponent::make('gantt', __DIR__.'/../resources/dist/gantt.js'),
            Css::make('gantt', __DIR__.'/../resources/dist/gantt.css')->loadedOnRequest(),
        ], 'packstub/filament-gantt');
    }
}
