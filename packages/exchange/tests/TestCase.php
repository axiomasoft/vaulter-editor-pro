<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Collaboration\Contracts\HostUserResolver;
use Vaulter\Collaboration\Testing\Fixtures\FakeHostUserResolver;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\EditorPro\Exchange\EditorProExchangeServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Concerns\UsesSuiteDatabase;
use Vaulter\Testing\Fixtures\TestUser;
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
            EditorProExchangeServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            HostUserResolver::class,
            static fn (): FakeHostUserResolver => new FakeHostUserResolver(userType: (new TestUser)->getMorphClass()),
        );
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
        $app['config']->set('vaulter.modules.collaboration', true);
        $app['config']->set('vaulter-collaboration.hub.webhook_secret', 'webhook-shared-secret');
        $app['config']->set('vaulter-collaboration.hub.jwt_keys', ['k1' => 'jwt-signing-secret-that-is-at-least-32-bytes']);
        $app['config']->set('vaulter-collaboration.hub.jwt_active_kid', 'k1');
        $app['config']->set('vaulter-collaboration.hub.issuer', 'https://vaulter.test');
        $app['config']->set('vaulter-collaboration.hub.timestamp_tolerance', 300);
        $app['config']->set('vaulter-editor-pro-exchange.cli_path', dirname(__DIR__, 2).'/docx/dist/cli.js');
        $app['config']->set('vaulter-editor-pro-exchange.auto_dispatch', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->migrateSuiteDatabase();
    }
}
