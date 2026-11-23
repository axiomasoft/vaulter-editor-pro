<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Vaulter\Laravel\VaulterPluginRegistrar;
use Vaulter\Modules\OffersModules;

/**
 * Laravel service provider offering {@see EditorProReviewModule} to the Vaulter kernel.
 */
#[Api(since: '1.0')]
final class EditorProReviewServiceProvider extends ServiceProvider
{
    use OffersModules;

    #[Override]
    public function register(): void
    {
        if ($this->moduleProviderRegistered()) {
            return;
        }

        VaulterPluginRegistrar::register($this->app, EditorProReviewModule::class, dirname(__DIR__).'/composer.json');
    }

    public function boot(): void
    {
        if ($this->moduleProviderBooted()) {
            return;
        }

        $this->loadTranslationsFrom(dirname(__DIR__).'/resources/lang', 'vaulter-editor-pro-review');
        $this->markModuleProviderBooted();
    }
}
