<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange;

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
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Errors\ExchangeProblemDefinitions;
use Vaulter\EditorPro\Exchange\Http\Problems\ExchangeProblemCodes;
use Vaulter\EditorPro\Exchange\Http\Support\ExchangeRouteLabels;
use Vaulter\EditorPro\Exchange\Jobs\ReleaseExchangePinsOnPurge;
use Vaulter\EditorPro\Exchange\Operations\ExchangeOperationPolicies;
use Vaulter\EditorPro\Exchange\Operations\ExchangeOperations;
use Vaulter\EditorPro\Exchange\Providers\ExchangeBindings;
use Vaulter\EditorPro\Exchange\Schema\ExchangeSchema;
use Vaulter\EditorPro\Exchange\Schema\ExchangeSchemaSets;
use Vaulter\Events\EventSink;
use Vaulter\Modules\Activation;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/**
 * Vaulter Editor Pro DOCX Exchange extension plugin (`vaulter.exchange`):
 * asynchronous DOCX import/export jobs, ZIP/XML security guards, Media blob pinning,
 * and atomic CAS import application.
 */
#[Api(since: '1.0')]
final class EditorProExchangeModule implements ExtensionPlugin, WithConfiguration, WithDiagnostics, WithModuleRuntime, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesDiagnostics;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'vaulter.exchange',
            version: '1.0.0',
            requires: ['vaulter.core', 'vaulter.drive', 'vaulter.documents', 'vaulter.media'],
            resourceRoot: dirname(__DIR__).'/resources',
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'vaulter-editor-pro-exchange';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/vaulter-editor-pro-exchange.php';
    }

    /** @return class-string<OptionsFactory> */
    #[Override]
    public function optionsClass(): string
    {
        return ExchangeOptions::class;
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
        return ['document_exchange_jobs', 'document_sections'];
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
        return [ExchangeSchemaSets::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaComponents(): array
    {
        return [ExchangeSchema::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [ExchangeProblemDefinitions::class];
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        return [
            ['slot' => RouteLabelSource::class, 'id' => 'exchange.routes', 'implementation' => ExchangeRouteLabels::class],
            ['slot' => DriveExtension::class, 'id' => 'exchange.jobs', 'implementation' => ExchangeOperations::class, 'key' => ExchangeOperations::class],
            ['slot' => EventSink::class, 'id' => 'exchange.purge-pins', 'implementation' => ReleaseExchangePinsOnPurge::class],
        ];
    }

    public function moduleRuntime(): PluginModuleSpec
    {
        $root = dirname(__DIR__);

        return new PluginModuleSpec(
            plugin: self::class,
            activation: Activation::Auto,
            protocolVersion: '1.0',
            requires: ['core' => '^1.0', 'drive' => '^1.0', 'documents' => '^1.0', 'media' => '^1.0'],
            routes: [$root.'/routes/exchange.php'],
            translations: ['vaulter-editor-pro-exchange' => $root.'/resources/lang'],
            bindings: [ExchangeBindings::class],
            problems: [ExchangeProblemCodes::class],
            operations: [ExchangeOperationPolicies::class],
        );
    }
}
