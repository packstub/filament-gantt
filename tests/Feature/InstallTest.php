<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Packstub\Gantt\GanttServiceProvider;

it('publishes the config file under the tag the docs name', function () {
    $paths = ServiceProvider::pathsToPublish(GanttServiceProvider::class, 'packstub-gantt-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/filament-gantt.php');

    $target = config_path('filament-gantt.php');
    File::delete($target);

    try {
        $this->artisan('vendor:publish', ['--tag' => 'packstub-gantt-config'])->assertSuccessful();

        expect(File::exists($target))->toBeTrue()
            ->and(require $target)->toHaveKey('default_scale');
    } finally {
        File::delete($target);
    }
});

it('names in the README and the docs only publish tags that publish something', function () {
    $root = dirname(__DIR__, 2);
    $tags = [];

    foreach ([$root.'/README.md', ...File::glob($root.'/docs/*.md')] as $file) {
        preg_match_all('/--tag=([\w-]+)/', File::get($file), $matches);
        $tags = [...$tags, ...$matches[1]];
    }

    expect($tags)->toContain('packstub-gantt-config', 'packstub-gantt-translations');

    foreach (array_unique($tags) as $tag) {
        expect(ServiceProvider::pathsToPublish(GanttServiceProvider::class, $tag))->not->toBeEmpty("--tag={$tag} publishes nothing");
    }
});

it('ships the same strings in every language, with plural forms for the count', function () {
    $en = require __DIR__.'/../../resources/lang/en/gantt.php';

    foreach (['ro', 'ru', 'de'] as $locale) {
        $strings = require __DIR__."/../../resources/lang/{$locale}/gantt.php";

        expect(array_keys($strings))->toBe(array_keys($en), $locale)
            ->and($strings['bars'])->toBeArray($locale)->toHaveKeys(['one', 'other']);
    }
});
