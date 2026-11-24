<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Documents\Contracts\RevisionPruneProtection;
use Vaulter\Documents\Contracts\SnapshotWriteHook;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorReconciler;
use Vaulter\EditorPro\Review\Configuration\ReviewOptions;
use Vaulter\EditorPro\Review\Errors\ReviewProblemDefinitions;
use Vaulter\EditorPro\Review\Http\Problems\ReviewProblemCodes;
use Vaulter\EditorPro\Review\Http\Support\ReviewRouteLabels;
use Vaulter\EditorPro\Review\Operations\AnchoredCommentOperations;
use Vaulter\EditorPro\Review\Operations\ReviewOperationPolicies;
use Vaulter\EditorPro\Review\Operations\RevisionPinOperations;
use Vaulter\EditorPro\Review\Operations\SuggestionOperations;
use Vaulter\EditorPro\Review\Providers\ReviewBindings;
use Vaulter\EditorPro\Review\Repositories\RevisionPinRepository;
use Vaulter\EditorPro\Review\Schema\ReviewSchema;
use Vaulter\EditorPro\Review\Schema\ReviewSchemaSets;
use Vaulter\EditorPro\Review\Sinks\RestoreAnchorTransitionSink;
use Vaulter\Events\EventSink;
use Vaulter\Modules\Activation;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/**
 * Vaulter Editor Pro Review extension plugin (`vaulter.review`):
 * immutable revision pins, read-only preview/compare, and generation-scoped range comment anchors.
 */
#[Api(since: '1.0')]
final class EditorProReviewModule implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithModuleRuntime, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesDiagnostics;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'vaulter.review',
            version: '1.0.0',
            requires: ['vaulter.core', 'vaulter.drive', 'vaulter.documents'],
            resourceRoot: dirname(__DIR__).'/resources',
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'vaulter-editor-pro-review';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter-editor-pro-review.php';
    }

    /** @return class-string<OptionsFactory> */
    #[Override]
    public function optionsClass(): string
    {
        return ReviewOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'v_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return ['document_revision_pins', 'document_comment_anchors', 'document_suggestions', 'document_comment_states'];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__).'/database/migrations';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [ReviewSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaComponents(): array
    {
        return [ReviewSchema::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [ReviewProblemDefinitions::class];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        return [
            ['slot' => RouteLabelSource::class, 'id' => 'review.routes', 'implementation' => ReviewRouteLabels::class],
            ['slot' => DriveExtension::class, 'id' => 'review.pins', 'implementation' => RevisionPinOperations::class, 'key' => RevisionPinOperations::class],
            ['slot' => DriveExtension::class, 'id' => 'review.comments', 'implementation' => AnchoredCommentOperations::class, 'key' => AnchoredCommentOperations::class],
            ['slot' => DriveExtension::class, 'id' => 'review.suggestions', 'implementation' => SuggestionOperations::class, 'key' => SuggestionOperations::class],
            ['slot' => EventSink::class, 'id' => RestoreAnchorTransitionSink::ID, 'implementation' => RestoreAnchorTransitionSink::class],
            ['slot' => SnapshotWriteHook::class, 'id' => 'review.snapshot-anchors', 'implementation' => SnapshotAnchorReconciler::class, 'key' => 'review'],
            ['slot' => RevisionPruneProtection::class, 'id' => 'review.revision-pins', 'implementation' => RevisionPinRepository::class, 'key' => 'review'],
        ];
    }

    public function moduleRuntime(): PluginModuleSpec
    {
        $root = dirname(__DIR__);

        return new PluginModuleSpec(
            plugin: self::class,
            activation: Activation::Auto,
            protocolVersion: '1.0',
            requires: ['core' => '^1.0', 'drive' => '^1.0', 'documents' => '^1.0'],
            routes: [$root.'/routes/review.php'],
            translations: ['vaulter-editor-pro-review' => $root.'/resources/lang'],
            bindings: [ReviewBindings::class],
            problems: [ReviewProblemCodes::class],
            operations: [ReviewOperationPolicies::class],
        );
    }
}
