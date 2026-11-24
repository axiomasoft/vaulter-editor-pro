<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Tests\E2E;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Vaulter\Collaboration\Contracts\HostUserResolver;
use Vaulter\Collaboration\Testing\Fixtures\FakeHostUserResolver;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

final class E2eServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->environment('local', 'testing', 'workbench')) {
            throw new LogicException('The E2E stand requires a local, testing, or workbench environment.');
        }

        $root = dirname(__DIR__, 3);
        $cachePath = getenv('E2E_CACHE_PATH') ?: sys_get_temp_dir().'/vaulter-ep-e2e-cache';
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }

        config([
            'app.env' => 'local',
            'app.debug' => true,
            'app.key' => 'base64:'.base64_encode(str_repeat('e', 32)),
            'app.url' => getenv('APP_URL') ?: 'http://127.0.0.1:8000',
            'cache.default' => 'file',
            'cache.stores.file.path' => $cachePath,
            'queue.default' => 'sync',
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'vaulter_e2e_test',
                'username' => getenv('DB_USERNAME') ?: 'vaulter',
                'password' => getenv('DB_PASSWORD') ?: 'secret',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
            ],
            'vaulter.modules.collaboration' => true,
            'vaulter-drive.access.permission_driver' => 'database',
            'vaulter-documents.tiptap.block_ids' => true,
            'vaulter-documents.revisions.checkpoint_interval_seconds' => 0,
            'vaulter.routes.middleware.authenticated' => ['api', E2eActingAs::class],
            'vaulter.routes.rate_limits.public' => 6000,
            'vaulter-collaboration.hub.url' => getenv('VAULTER_COLLAB_URL') ?: 'ws://127.0.0.1:1234?protocol=2',
            'vaulter-collaboration.hub.jwt_keys' => getenv('VAULTER_COLLAB_JWT_KEYS') ?: ['e2e-1' => 'e2e-collab-jwt-secret-that-is-at-least-32-bytes'],
            'vaulter-collaboration.hub.jwt_active_kid' => getenv('VAULTER_COLLAB_JWT_ACTIVE_KID') ?: 'e2e-1',
            'vaulter-collaboration.hub.issuer' => getenv('VAULTER_COLLAB_ISSUER') ?: 'http://127.0.0.1:8000',
            'vaulter-collaboration.hub.webhook_secret' => getenv('VAULTER_COLLAB_WEBHOOK_SECRET') ?: 'e2e-collab-webhook-secret',
            'vaulter-editor-pro-exchange.auto_dispatch' => true,
            'vaulter-editor-pro-exchange.node_binary' => getenv('VAULTER_DOCX_NODE_BIN') ?: 'node',
            'vaulter-editor-pro-exchange.cli_path' => getenv('VAULTER_DOCX_CLI_PATH') ?: $root.'/packages/docx/dist/cli.js',
        ]);
    }

    public function boot(): void
    {
        $this->app->booted(static function (Application $app): void {
            $app->bind(HostUserResolver::class, static fn (): FakeHostUserResolver => new FakeHostUserResolver(
                userType: (new TestUser)->getMorphClass(),
            ));
            config(['vaulter-collaboration.compaction.trigger_updates' => 2]);
        });

        Route::get('/e2e/health', static fn () => response()->json(['status' => 'ok']));

        Route::post('/e2e/setup', static function (Request $request) {
            $payload = $request->json()->all();
            $title = is_string($payload['title'] ?? null) ? $payload['title'] : 'E2E Document';
            $content = is_array($payload['content'] ?? null) ? $payload['content'] : [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'attrs' => ['blockId' => 'blk_1'],
                        'content' => [
                            ['type' => 'text', 'text' => 'Hello target clause in paragraph one.'],
                        ],
                    ],
                    [
                        'type' => 'paragraph',
                        'attrs' => ['blockId' => 'blk_2'],
                        'content' => [
                            ['type' => 'text', 'text' => 'Second paragraph tail.'],
                        ],
                    ],
                ],
            ];

            $drive = DriveTestHelpers::drive(random_int(1, 2_000_000_000));
            $storage = $drive->model();

            $node = app(CreateDocumentAction::class)->execute(
                DriveContext::system($storage, 'e2e setup'),
                new CreateDocumentInput(
                    storage: $storage,
                    name: $title,
                    content: TipTapContent::fromArray($content),
                    parent: DriveTestHelpers::model($drive->root()),
                ),
            );

            AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 1);
            AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 2);
            AccessTestHelpers::grant($node, RoleRegistry::WRITER, subjectId: 3);
            DriveTestHelpers::actor($drive, 4);

            $document = Document::query()->where('node_id', $node->getKey())->firstOrFail();

            return response()->json([
                'node_id' => (string) $node->getKey(),
                'drive_id' => (string) $node->drive_id,
                'first_revision_id' => (string) $document->current_revision_id,
                'generation' => (int) $document->collab_epoch,
                'state_version' => (int) $document->state_version,
                'content' => $content,
            ]);
        });

        Route::post('/e2e/document/{node}/revision', static function (Request $request, string $node) {
            $payload = $request->json()->all();
            $content = is_array($payload['content'] ?? null) ? $payload['content'] : null;
            abort_if($content === null, 422, 'content is required');

            $nodeModel = DriveTestHelpers::model($node);
            $storage = $nodeModel->storage()->firstOrFail();
            $userId = (int) ($request->header('X-E2E-User') ?? '1');
            $drive = Vaulter::drive($storage)->actingAs(AccessTestHelpers::user($userId));
            $docs = app(DocumentsManager::class)->in($drive);

            $current = $docs->content($node);
            $updated = $docs->updateContent($node, $content, $current->stateVersion);
            $label = is_string($payload['label'] ?? null) ? $payload['label'] : null;
            $revision = $docs->snapshot($node, $label ?? 'E2E Revision');

            return response()->json([
                'revision_id' => (string) $revision->id,
                'number' => $revision->number,
                'state_version' => $updated->stateVersion,
                'generation' => $updated->epoch,
            ]);
        });

        Route::get('/e2e/document/{node}/state', static function (string $node) {
            $document = Document::query()->with(['payload', 'syncState'])->where('node_id', $node)->firstOrFail();

            return response()->json([
                'node_id' => $node,
                'generation' => (int) $document->collab_epoch,
                'state_version' => (int) $document->state_version,
                'write_mode' => $document->write_mode->value,
                'current_revision_id' => (string) $document->current_revision_id,
                'content' => $document->payload?->content,
                'pins' => [],
            ]);
        });
    }
}
