<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\JevModeration\Facades\JevModeration;
use Phoenix1331\JevModeration\JevModerationManager;

/*
 * The public API is everything not marked @internal. These tests keep the
 * facade honest, the internal boundary deliberate, and Eloquent out of the
 * places that promise plain data.
 */

/** Namespaces whose classes are implementation detail. */
const INTERNAL_NAMESPACES = [
    'Phoenix1331\\JevModeration\\Jobs\\',
    'Phoenix1331\\JevModeration\\Models\\Builders\\',
    'Phoenix1331\\JevModeration\\Moderation\\',
    'Phoenix1331\\JevModeration\\Policies\\',
    'Phoenix1331\\JevModeration\\Scopes\\',
];

/** Internal classes outside those namespaces. */
const INTERNAL_CLASSES = [
    'Phoenix1331\\JevModeration\\Drivers\\DriverManager',
    'Phoenix1331\\JevModeration\\Drivers\\JevDriver',
];

/**
 * @return list<class-string>
 */
function packageClasses(): array
{
    $classes = [];
    $src = dirname(__DIR__, 2).'/src/';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
        if ($file->getExtension() === 'php') {
            $classes[] = 'Phoenix1331\\JevModeration\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($src)));
        }
    }

    sort($classes);

    return $classes;
}

function isInternal(string $class): bool
{
    foreach (INTERNAL_NAMESPACES as $namespace) {
        if (str_starts_with($class, $namespace)) {
            return true;
        }
    }

    return in_array($class, INTERNAL_CLASSES, true);
}

function docComment(string $class): string
{
    return (string) (new ReflectionClass($class))->getDocComment();
}

it('marks exactly the implementation classes @internal', function (): void {
    foreach (packageClasses() as $class) {
        expect(str_contains(docComment($class), '@internal'))->toBe(isInternal($class), "{$class} should ".(isInternal($class) ? '' : 'not ').'be @internal');
    }
});

it('lists every manager method on the facade, and nothing else', function (): void {
    preg_match_all('/@method static \S+ (\w+)\((.*)\)\s*$/m', docComment(JevModeration::class), $matches, PREG_SET_ORDER);

    $documented = [];

    foreach ($matches as [, $method, $parameters]) {
        preg_match_all('/\$(\w+)/', $parameters, $names);
        $documented[$method] = $names[1];
    }

    $actual = [];

    foreach ((new ReflectionClass(JevModerationManager::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isConstructor()) {
            $actual[$method->getName()] = array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());
        }
    }

    ksort($documented);
    ksort($actual);

    expect($documented)->toBe($actual);
});

it('hands out no Eloquent models, builders or collections from plain-data APIs', function (): void {
    // Events, the trait and ModerationDecision carry models on purpose.
    $plain = array_filter(packageClasses(), fn (string $class): bool => ! isInternal($class)
        && ! str_contains($class, '\\Events\\')
        && ! str_contains($class, '\\Concerns\\')
        && ! str_contains($class, '\\Models\\')
        && ! str_contains($class, '\\Facades\\')
        && ! str_contains($class, 'ServiceProvider'));

    $eloquent = fn (?ReflectionType $type): bool => $type !== null && array_filter(
        $type instanceof ReflectionUnionType ? $type->getTypes() : [$type],
        fn (ReflectionType $part): bool => $part instanceof ReflectionNamedType && ! $part->isBuiltin()
            && (is_a($part->getName(), Model::class, true) || is_a($part->getName(), Builder::class, true) || is_a($part->getName(), Collection::class, true)),
    ) !== [];

    foreach ($plain as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            expect($eloquent($method->getReturnType()))->toBeFalse("{$class}::{$method->getName()}() returns Eloquent");
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            expect($eloquent($property->getType()))->toBeFalse("{$class}::\${$property->getName()} is Eloquent");
        }
    }
});

it('registers the facade alias for package discovery', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel']['aliases'])->toBe(['JevModeration' => JevModeration::class]);
});

arch('the package is headless: no UI, views, routes or Filament')
    ->expect('Phoenix1331\JevModeration')
    ->not->toUse([
        'Filament',
        'Livewire',
        'Illuminate\View',
        'Illuminate\Routing',
        'Illuminate\Support\Facades\View',
        'Illuminate\Support\Facades\Route',
        'view',
        'route',
    ]);

it('keeps every interface in Contracts', function (): void {
    foreach (packageClasses() as $class) {
        if ((new ReflectionClass($class))->isInterface()) {
            expect($class)->toStartWith('Phoenix1331\\JevModeration\\Contracts\\');
        }
    }
});
