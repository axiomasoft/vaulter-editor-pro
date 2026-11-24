<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Operations\OperationPolicy;
use Vaulter\Schema\SchemaHelpers;

it('declares all review operations and binds every mutating route 1:1 with idempotency policies', function (): void {
    /** @var array<string, OperationPolicy> $policies */
    $policies = [];
    foreach (app(OperationCatalog::class)->all() as $name => $entry) {
        if ($entry['module'] === 'review') {
            $policies[$name] = $entry['policy'];
        }
    }

    $routes = [];
    foreach (Router::getRoutes()->getRoutes() as $route) {
        if (! $route instanceof Route) {
            continue;
        }
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'vaulter.v1.review.')) {
            continue;
        }
        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $routes[substr($name, strlen('vaulter.v1.'))] = $route;
        }
    }

    $declaredRoutes = [];
    foreach ($policies as $policy) {
        foreach ($policy->routes as $routeName) {
            $declaredRoutes[] = $routeName;
            if ($policy->idempotency === IdempotencyPolicy::Receipt) {
                expect($routes[$routeName]->gatherMiddleware())->toContain('vaulter.idempotency');
            }
        }
    }

    sort($declaredRoutes);
    $actualRoutes = array_keys($routes);
    sort($actualRoutes);
    expect($declaredRoutes)->toBe($actualRoutes);

    $usedOps = [];
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $file) {
        if (preg_match_all("/new OperationSpec\\('(review\\.[a-z_.]+)'/", $file->getContents(), $m) > 0) {
            array_push($usedOps, ...$m[1]);
        }
    }
    $usedOps = array_values(array_unique($usedOps));
    sort($usedOps);
    $policyNames = array_keys($policies);
    sort($policyNames);

    expect($usedOps)->toBe($policyNames);
});

it('never imports internal vaulter classes or Eloquent models on #[Api] files', function (): void {
    $apiViolations = [];
    $internalImports = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $file) {
        $src = $file->getContents();
        if (str_contains($src, '#[Api') && str_contains($src, 'Illuminate\\Database\\Eloquent\\Model')) {
            $apiViolations[] = $file->getRelativePathname();
        }
        if (preg_match_all('/^use\s+(Vaulter\\\\(?!EditorPro\\\\Review)[A-Za-z0-9_\\\\]+);/m', $src, $matches)) {
            foreach ($matches[1] as $fqcn) {
                if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
                    $ref = new ReflectionClass($fqcn);
                    if ($ref->getAttributes('Packman\\Attributes\\Internal') !== []) {
                        $internalImports[] = $file->getRelativePathname().' -> '.$fqcn;
                    }
                }
            }
        }
    }

    expect($apiViolations)->toBe([])
        ->and($internalImports)->toBe([]);
});

it('covers review document foreign keys without a redundant single-column document_id index', function (): void {
    $schema = file_get_contents(dirname(__DIR__, 2).'/src/Schema/ReviewSchema.php');
    expect($schema)->toBeString()
        ->and($schema)->not->toContain('foreignKeyIndex(');

    $helpers = app(SchemaHelpers::class);
    foreach (['document_revision_pins', 'document_comment_anchors', 'document_suggestions'] as $entity) {
        $table = $helpers->table($entity);
        $covering = false;
        foreach (Schema::getIndexes($table) as $index) {
            expect($index['name'])->not->toEndWith('document_id_index')
                ->and($index['name'])->not->toEndWith('document_id_fk_idx');
            if ($index['primary'] !== true) {
                expect($index['columns'])->not->toBe(['document_id']);
            }
            if (($index['columns'][0] ?? null) === 'document_id' && count($index['columns']) > 1) {
                $covering = true;
            }
        }
        expect($covering)->toBeTrue();
    }
});
