<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Vaulter\EditorPro\Review\Anchors\SelectorValidator;
use Vaulter\EditorPro\Review\Anchors\SnapshotAnchorResolver;
use Vaulter\EditorPro\Review\Configuration\ReviewOptions;
use Vaulter\EditorPro\Review\Http\Support\ReviewPreconditions;
use Vaulter\EditorPro\Review\Operations\AnchoredCommentOperations;
use Vaulter\EditorPro\Review\Operations\RevisionPinOperations;
use Vaulter\EditorPro\Review\Operations\SuggestionOperations;
use Vaulter\EditorPro\Review\Repositories\CommentAnchorRepository;
use Vaulter\EditorPro\Review\Repositories\ReviewLookup;
use Vaulter\EditorPro\Review\Repositories\RevisionPinRepository;
use Vaulter\EditorPro\Review\Repositories\SuggestionRepository;
use Vaulter\EditorPro\Review\ReviewManager;
use Vaulter\EditorPro\Review\Suggestions\ReviewCapabilities;
use Vaulter\EditorPro\Review\Suggestions\SnapshotStructuralEdit;
use Vaulter\EditorPro\Review\Suggestions\SuggestionJournalBridge;
use Vaulter\EditorPro\Review\Suggestions\SuggestionOverlapDetector;
use Vaulter\Modules\ModuleBindings;

/** Container bindings and configuration validator for `vaulter.review`. */
#[Internal]
final class ReviewBindings implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->bind(ReviewOptions::class, static function (Container $container): ReviewOptions {
            $raw = $container->bound(Repository::class)
                ? $container->make(Repository::class)->get('vaulter-editor-pro-review', [])
                : [];

            /** @var array<string, mixed> $config */
            $config = is_array($raw) ? $raw : [];

            return ReviewOptions::fromArray($config);
        });

        $validate = static function () use ($app): void {
            $app->make(ReviewOptions::class);
        };
        $app->instance('vaulter.config_validator.review', $validate);
        $app->tag('vaulter.config_validator.review', 'vaulter.config_validators');
        ConfigSectionRegistration::registerValidator($app, 'vaulter.editor-pro-review', $validate);

        $app->scoped(SelectorValidator::class);
        $app->scoped(SnapshotAnchorResolver::class);
        $app->scoped(ReviewLookup::class);
        $app->scoped(RevisionPinRepository::class);
        $app->scoped(CommentAnchorRepository::class);
        $app->scoped(SuggestionRepository::class);
        $app->scoped(ReviewCapabilities::class);
        $app->scoped(SnapshotStructuralEdit::class);
        $app->scoped(SuggestionOverlapDetector::class);
        $app->scoped(SuggestionJournalBridge::class);
        $app->scoped(ReviewPreconditions::class);
        $app->scoped(ReviewManager::class);
        $app->alias(ReviewManager::class, 'vaulter.review');

        $app->bind(RevisionPinOperations::class);
        $app->bind(AnchoredCommentOperations::class);
        $app->bind(SuggestionOperations::class);
    }

    public function boot(Application $app): void {}
}
