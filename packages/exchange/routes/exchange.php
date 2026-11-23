<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesOptions;
use Vaulter\EditorPro\Exchange\Http\Controllers\ExchangeJobController;

$routes = app(RoutesOptions::class);
$id = '[A-Za-z0-9_-]+';

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware($routes->authenticatedMiddleware())
    ->where(['node' => $id, 'job' => $id])
    ->group(static function (): void {
        Route::post('documents/{node}/exchange-jobs', [ExchangeJobController::class, 'store'])
            ->name('exchange.jobs.store')
            ->middleware('vaulter.idempotency');

        Route::get('documents/{node}/exchange-jobs/{job}', [ExchangeJobController::class, 'show'])
            ->name('exchange.jobs.show');

        Route::get('documents/{node}/exchange-jobs/{job}/file', [ExchangeJobController::class, 'file'])
            ->name('exchange.jobs.file');

        Route::post('documents/{node}/exchange-jobs/{job}/apply', [ExchangeJobController::class, 'apply'])
            ->name('exchange.jobs.apply')
            ->middleware('vaulter.idempotency');
    });
