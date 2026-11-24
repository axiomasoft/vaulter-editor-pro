<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\EditorPro\Exchange\EditorProExchangeServiceProvider;
use Vaulter\EditorPro\Review\EditorProReviewServiceProvider;
use Vaulter\EditorPro\Tests\E2E\E2eServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

$root = dirname(__DIR__, 3);
require_once $root.'/vendor/autoload.php';
require_once __DIR__.'/E2eActingAs.php';
require_once __DIR__.'/E2eServiceProvider.php';

/** @var Application|null $app */
static $app = null;

if ($app === null) {
    $app = Testbench::create(
        basePath: $root.'/vendor/orchestra/testbench-core/laravel',
        options: [
            'enabled_package_discoveries' => false,
            'extra' => [
                'dont-discover' => ['*'],
                'providers' => [
                    E2eServiceProvider::class,
                    VaulterServiceProvider::class,
                    VaulterMediaServiceProvider::class,
                    VaulterDriveServiceProvider::class,
                    VaulterDocumentsServiceProvider::class,
                    VaulterCollaborationServiceProvider::class,
                    EditorProReviewServiceProvider::class,
                    EditorProExchangeServiceProvider::class,
                ],
            ],
        ],
    );
}

$request = Request::capture();
$app->handleRequest($request);
