<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Contracts\DocxExchangeRuntime;
use Vaulter\EditorPro\Exchange\Contracts\ExchangeActorResolver;
use Vaulter\EditorPro\Exchange\ExchangeManager;
use Vaulter\EditorPro\Exchange\Operations\ExchangeJobProcessor;
use Vaulter\EditorPro\Exchange\Operations\ExchangeOperations;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeJobRepository;
use Vaulter\EditorPro\Exchange\Repositories\ExchangeLookup;
use Vaulter\EditorPro\Exchange\Repositories\JournalImportReservations;
use Vaulter\EditorPro\Exchange\Runtime\ConfigAuthActorResolver;
use Vaulter\EditorPro\Exchange\Runtime\NodeDocxExchangeRuntime;
use Vaulter\EditorPro\Exchange\Security\DocxZipArchiveGuard;
use Vaulter\EditorPro\Review\Contracts\PendingSuggestionReservations;
use Vaulter\Modules\ModuleBindings;

/** Container bindings and configuration validator for `vaulter.exchange`. */
#[Internal]
final class ExchangeBindings implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->bind(ExchangeOptions::class, static function (Container $container): ExchangeOptions {
            $raw = $container->bound(Repository::class)
                ? $container->make(Repository::class)->get('vaulter-editor-pro-exchange', [])
                : [];

            /** @var array<string, mixed> $config */
            $config = is_array($raw) ? $raw : [];

            return ExchangeOptions::fromArray($config);
        });

        $validate = static function () use ($app): void {
            $app->make(ExchangeOptions::class);
        };
        $app->instance('vaulter.config_validator.exchange', $validate);
        $app->tag('vaulter.config_validator.exchange', 'vaulter.config_validators');
        ConfigSectionRegistration::registerValidator($app, 'vaulter.editor-pro-exchange', $validate);

        $app->scoped(DocxZipArchiveGuard::class);
        $app->scoped(ExchangeLookup::class);
        $app->scoped(ExchangeJobRepository::class);
        $app->scoped(DocxExchangeRuntime::class, NodeDocxExchangeRuntime::class);
        $app->scoped(ExchangeActorResolver::class, ConfigAuthActorResolver::class);
        if (interface_exists(PendingSuggestionReservations::class)) {
            $app->scoped(PendingSuggestionReservations::class, JournalImportReservations::class);
        }
        $app->scoped(ExchangeJobProcessor::class);
        $app->scoped(ExchangeManager::class);
        $app->alias(ExchangeManager::class, 'vaulter.exchange');

        $app->bind(ExchangeOperations::class);
    }

    public function boot(Application $app): void {}
}
