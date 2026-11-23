<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Vaulter\EditorPro\Exchange\Console\RecoverExchangeJobsCommand;
use Vaulter\Laravel\VaulterPluginRegistrar;
use Vaulter\Modules\OffersModules;

/**
 * Laravel service provider offering {@see EditorProExchangeModule} to the Vaulter kernel.
 */
#[Api(since: '1.0')]
final class EditorProExchangeServiceProvider extends ServiceProvider
{
    use OffersModules;

    #[Override]
    public function register(): void
    {
        if ($this->moduleProviderRegistered()) {
            return;
        }

        VaulterPluginRegistrar::register($this->app, EditorProExchangeModule::class, dirname(__DIR__).'/composer.json');
    }

    public function boot(): void
    {
        if ($this->moduleProviderBooted()) {
            return;
        }

        $this->loadTranslationsFrom(dirname(__DIR__).'/resources/lang', 'vaulter-editor-pro-exchange');

        if ($this->app->runningInConsole()) {
            $this->commands([RecoverExchangeJobsCommand::class]);
        }

        $this->markModuleProviderBooted();
    }
}
