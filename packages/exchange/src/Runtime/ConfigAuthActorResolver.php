<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Runtime;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Collaboration\Contracts\HostUserResolver;
use Vaulter\EditorPro\Exchange\Contracts\ExchangeActorResolver;
use Vaulter\ValueObjects\Access\Actor;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Resolves the acting `Authorizable` user from `effective_subject` using configured host resolvers
 * or Laravel's configured auth user provider.
 */
#[Internal]
final readonly class ConfigAuthActorResolver implements ExchangeActorResolver
{
    public function __construct(private Container $container) {}

    public function resolve(string $tenantId, string $projectScope, string $effectiveSubject): ?Authorizable
    {
        $sep = strrpos($effectiveSubject, ':');
        if ($sep === false || $sep === 0 || $sep === strlen($effectiveSubject) - 1) {
            return null;
        }

        $type = substr($effectiveSubject, 0, $sep);
        $id = substr($effectiveSubject, $sep + 1);

        if (interface_exists(HostUserResolver::class) && $this->container->bound(HostUserResolver::class)) {
            $resolved = $this->container->make(HostUserResolver::class)->find(new ActorRef($type, $id));
            if ($resolved instanceof Authorizable) {
                return $resolved;
            }
        }

        if ($this->container->bound(AuthManager::class)) {
            try {
                $auth = $this->container->make(AuthManager::class);
                $guardName = $auth->getDefaultDriver();
                $providerName = $this->container->make('config')->get("auth.guards.{$guardName}.provider");
                $provider = is_string($providerName) ? $auth->createUserProvider($providerName) : $auth->createUserProvider();
                if (! $provider instanceof EloquentUserProvider) {
                    return null;
                }
                $model = $provider->getModel();
                $expected = new $model;
                if ($expected->getMorphClass() !== $type) {
                    return null;
                }
                $user = $provider->retrieveById($id);
                if (! $user instanceof Authorizable) {
                    return null;
                }
                $resolved = Actor::user($user)->ref();

                return $resolved->type === $type && (string) $resolved->id === $id ? $user : null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
