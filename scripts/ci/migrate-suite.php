<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Symfony\Component\Console\Input\ArrayInput;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\EditorPro\Exchange\EditorProExchangeServiceProvider;
use Vaulter\EditorPro\Review\EditorProReviewServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Schema\ModuleSchemas;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = Testbench::create(options: [
    'extra' => [
        'providers' => [
            VaulterServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
            VaulterCollaborationServiceProvider::class,
            EditorProReviewServiceProvider::class,
            EditorProExchangeServiceProvider::class,
        ],
    ],
]);

$config = $app->make('config');
$config->set('database.default', 'testing');
$config->set('database.connections.testing', SuiteDatabaseConnection::resolve());
$config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

$schemas = $app->make(ModuleSchemas::class);
$paths = $schemas->migrationPaths();
if ($paths === []) {
    fwrite(STDERR, "migrate-suite: module kernel offered no migration paths\n");
    exit(1);
}

fwrite(STDOUT, 'migrate-suite: '.count($paths)." path(s)\n");

$kernel = $app->make(Kernel::class);
$status = $kernel->call('migrate', [
    '--database' => 'testing',
    '--force' => true,
    '--realpath' => true,
    '--path' => $paths,
]);
$kernel->terminate(new ArrayInput([]), $status);

if ($status !== 0) {
    fwrite(STDERR, "migrate-suite: migrate exited {$status}\n");
}

exit($status);
