<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\EditorPro\Review\EditorProReviewServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Concerns\UsesSuiteDatabase;
use Vaulter\VaulterServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use UsesSuiteDatabase;

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
            VaulterCollaborationServiceProvider::class,
            EditorProReviewServiceProvider::class,
        ];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $this->pinSuiteConnection($app);
        $this->pinOpenSuite($app);
        if (is_string($ids = env('VAULTER_TEST_IDS_STRATEGY')) && $ids !== '') {
            $app['config']->set('vaulter.ids.strategy', $ids);
        }
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        $app['config']->set('vaulter-drive.access.permission_driver', 'database');
        $app['config']->set('vaulter-documents.tiptap.block_ids', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->migrateSuiteDatabase();
    }
}
