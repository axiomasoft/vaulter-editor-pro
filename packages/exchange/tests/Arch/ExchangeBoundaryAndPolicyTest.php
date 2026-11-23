<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Operations\IdempotencyPolicy;
use Vaulter\Operations\OperationCatalog;
use Vaulter\Operations\OperationPolicy;

it('declares exchange operation policies and binds every mutating route 1:1 with vaulter.idempotency', function (): void {
    /** @var array<string, OperationPolicy> $policies */
    $policies = [];
    foreach (app(OperationCatalog::class)->all() as $name => $entry) {
        if ($entry['module'] === 'exchange') {
            $policies[$name] = $entry['policy'];
        }
    }

    $routes = [];
    foreach (Router::getRoutes()->getRoutes() as $route) {
        if (! $route instanceof Route) {
            continue;
        }
        $name = (string) $route->getName();
        if (! str_starts_with($name, 'vaulter.v1.exchange.')) {
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
    expect($declaredRoutes)->toBe($actualRoutes)
        ->and(array_keys($policies))->toContain('exchange.jobs.register', 'exchange.jobs.apply');
});

it('never imports internal vaulter classes or Eloquent models on #[Api] files', function (): void {
    $apiViolations = [];
    $internalImports = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $file) {
        $src = $file->getContents();
        if (str_contains($src, '#[Api') && preg_match('/\bIlluminate\\\\Database\\\\Eloquent\\\\Model\b/', $src) === 1) {
            $apiViolations[] = $file->getRelativePathname();
        }
        if (preg_match_all('/^use\s+(Vaulter\\\\(?!EditorPro\\\\Exchange)[A-Za-z0-9_\\\\]+);/m', $src, $matches)) {
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

it('creates v_document_exchange_jobs with required columns and foreign key indexes', function (): void {
    $table = app(DatabaseOptions::class)->table('document_exchange_jobs');

    expect(Schema::hasTable($table))->toBeTrue()
        ->and(Schema::hasColumns($table, [
            'id',
            'tenant_id',
            'project_scope',
            'effective_subject',
            'document_id',
            'request_key',
            'request_fingerprint',
            'kind',
            'format',
            'profile_id',
            'source_blob_id',
            'source_revision_id',
            'source_generation',
            'source_position',
            'output_blob_id',
            'apply_command_id',
            'apply_receipt_id',
            'state',
            'diagnostics_report',
            'error_code',
            'lease_token',
            'lease_until',
            'created_at',
            'updated_at',
            'finished_at',
        ]))->toBeTrue();
});
