<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Suggestions;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Collaboration\Configuration\CollaborationOptions;
use Vaulter\Collaboration\Persistence\CommandActor;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Collaboration\Persistence\LogSnapshot;
use Vaulter\Collaboration\Persistence\RoomWriteConflict;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;
use Vaulter\Support\Identity;

/**
 * Optional runtime bridge to `vaulter-collaboration` (`CommandJournal` + `UpdateLog`)
 * without importing `#[Internal]` foreign classes.
 */
#[Internal]
final readonly class SuggestionJournalBridge
{
    public function __construct(private Container $container) {}

    public function isAvailable(): bool
    {
        return $this->journal() !== null;
    }

    public function journal(): ?CommandJournal
    {
        if (! class_exists(CommandJournal::class) || ! class_exists(CollaborationOptions::class)) {
            return null;
        }
        if (! $this->container->bound(CommandJournal::class) && ! $this->container->bound(CollaborationOptions::class)) {
            return null;
        }

        try {
            return $this->container->make(CommandJournal::class);
        } catch (Throwable) {
            return null;
        }
    }

    public function updateLog(): ?UpdateLog
    {
        if (! class_exists(UpdateLog::class)) {
            return null;
        }
        if (! $this->container->bound(UpdateLog::class)) {
            return null;
        }

        try {
            return $this->container->make(UpdateLog::class);
        } catch (Throwable) {
            return null;
        }
    }

    public function snapshot(int|string $nodeId, int $generation): ?LogSnapshot
    {
        $log = $this->updateLog();
        if ($log === null) {
            return null;
        }

        return $log->snapshot((string) $nodeId, $generation);
    }

    public function actor(DriveContext $context, int|string $nodeId, string $subject): CommandActor
    {
        $ref = $context->actor->ref();
        $driveTenant = $context->operation->tenant->id;
        $commandTenant = null;
        if (is_string($driveTenant) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $driveTenant) === 1 && (string) (int) $driveTenant === $driveTenant) {
            $commandTenant = $driveTenant;
        }

        return new CommandActor(
            principal: $ref,
            tenant: $commandTenant,
            project: $context->operation->scope->kind.':'.$context->operation->scope->id,
            subject: $subject,
            driveId: $context->operation->scope->id,
            resourceId: Identity::stringify($nodeId),
        );
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    public function register(
        CommandActor $actor,
        string $commandId,
        string $proposalUuid,
        string $digestHex,
        int $generation,
        int $schemaVersion,
        int $readPosition,
        array $intent,
    ): array {
        $journal = $this->journal();
        if ($journal === null) {
            throw SuggestionConflictException::because('Collaboration CommandJournal is unavailable.', 'journal_unavailable');
        }

        try {
            return $this->stringMap($journal->register(
                scope: $actor,
                key: $commandId,
                proposal: $proposalUuid,
                digest: $digestHex,
                generation: $generation,
                schema: $schemaVersion,
                readPosition: $readPosition,
                intent: $intent,
            ));
        } catch (RoomWriteConflict $conflict) {
            throw SuggestionConflictException::because(
                sprintf('Collaboration journal refused command (%s).', $conflict->reason),
                $conflict->reason,
            );
        }
    }

    /** @return array<string, mixed>|null */
    public function status(CommandActor $actor, string $commandId): ?array
    {
        $journal = $this->journal();
        if ($journal === null) {
            return null;
        }

        try {
            $res = $journal->status($actor, $commandId);

            return is_array($res) ? $this->stringMap($res) : null;
        } catch (RoomWriteConflict) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private function stringMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    public static function canonicalDigest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR));
    }

    public static function deterministicUuid(string $seed): string
    {
        $hash = hash('sha256', $seed);
        $b = substr($hash, 0, 32);
        $timeHi = (hexdec(substr($b, 12, 4)) & 0x0FFF) | 0x4000;
        $clockSeq = (hexdec(substr($b, 16, 4)) & 0x3FFF) | 0x8000;

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($b, 0, 8),
            substr($b, 8, 4),
            $timeHi,
            $clockSeq,
            substr($b, 20, 12),
        );
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
