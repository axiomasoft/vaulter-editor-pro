<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use stdClass;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Enums\SuggestionKind;
use Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException;

#[Internal]
final readonly class SuggestionRepository
{
    public function __construct(private DatabaseOptions $options) {}

    public function query(Connection $connection): Builder
    {
        return $connection->table($this->options->table('document_suggestions'));
    }

    public function forDocument(Connection $connection, string $documentId, int $generation): Builder
    {
        return $this->query($connection)
            ->where('document_id', $documentId)
            ->where('generation', $generation)
            ->whereIn('kind', SuggestionKind::allValues());
    }

    /** @param list<string> $ids */
    public function lockForMutation(Connection $connection, string $documentId, int $generation, array $ids, ?string $groupId): Builder
    {
        return $this->forDocument($connection, $documentId, $generation)
            ->where(static function (Builder $rows) use ($ids, $groupId): void {
                $rows->where('state', 'open')->orWhereIn('id', $ids);
                if ($groupId !== null) {
                    $rows->orWhere('group_id', $groupId);
                }
            })->lockForUpdate();
    }

    /** @return list<TextSuggestion> */
    public function read(Builder $query): array
    {
        return array_values($query->orderBy('id')->get()->map(self::data(...))->all());
    }

    /** @return array<string, mixed>|null */
    public function readProvenance(Connection $connection, string $id): ?array
    {
        return self::provenance($this->query($connection)->where('id', $id)->lockForUpdate()->value('provenance_json'));
    }

    /**
     * The provenance of every row of `$query` that has one, keyed by suggestion id, in one read.
     *
     * @return array<string, array<string, mixed>>
     */
    public function readProvenances(Builder $query): array
    {
        $out = [];
        foreach ((clone $query)->whereNotNull('provenance_json')->get(['id', 'provenance_json']) as $row) {
            $provenance = self::provenance($row->provenance_json ?? null);
            if ($provenance !== null && is_string($row->id ?? null)) {
                $out[$row->id] = $provenance;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function provenance(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? self::stringKeyMap($decoded) : null;
    }

    /** @param array<string, int|string|null> $values */
    public function update(Builder $query, TextSuggestion $suggestion, array $values): void
    {
        if ((clone $query)->where('id', $suggestion->id)->where('version', $suggestion->version)->update($values + [
            'version' => $suggestion->version + 1,
            'updated_at' => now(),
        ]) !== 1) {
            throw new SuggestionConflictException('The suggestion version has changed.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function selector(
        int $from,
        int $to,
        ?string $parentId,
        bool $conflicted = false,
        array $extra = [],
        ?string $conflictReason = null,
        ?string $conflictWithId = null,
    ): string {
        $data = array_merge($extra, [
            'schema' => 1,
            'from_utf16' => $from,
            'to_utf16' => $to,
            'parentId' => $parentId,
            'conflicted' => $conflicted,
        ]);
        if ($conflictReason !== null) {
            $data['conflict_reason'] = $conflictReason;
        } else {
            unset($data['conflict_reason']);
        }
        if ($conflictWithId !== null) {
            $data['conflict_with_id'] = $conflictWithId;
        } else {
            unset($data['conflict_with_id']);
        }

        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $provenance */
    public static function encodeProvenance(array $provenance): ?string
    {
        if ($provenance === []) {
            return null;
        }

        return json_encode($provenance, JSON_THROW_ON_ERROR);
    }

    private static function data(stdClass $row): TextSuggestion
    {
        /** @var array{id: string, group_id: string, proposal_id: string|null, document_id: int|string, generation: int|string, base_position: int|string, schema_version: int|string, author_subject: string, provenance_json?: string|null, kind: string, target_block_id: string, target_selector: string, payload: string, state: string, version: int|string, receipt_id: string|null, decided_by_subject: string|null, decided_at: string|null} $values */
        $values = (array) $row;
        $rawSelector = json_decode($values['target_selector'], true, flags: JSON_THROW_ON_ERROR);
        $selector = is_array($rawSelector) ? self::stringKeyMap($rawSelector) : [];
        $rawPayload = json_decode($values['payload'], true, flags: JSON_THROW_ON_ERROR);
        $payload = is_array($rawPayload) ? self::stringKeyMap($rawPayload) : [];

        $provenance = [];
        $rawProvenance = $values['provenance_json'] ?? null;
        if (is_string($rawProvenance) && $rawProvenance !== '') {
            $decodedProv = json_decode($rawProvenance, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decodedProv)) {
                $provenance = self::stringKeyMap($decodedProv);
            }
        }

        $pendingJournal = is_array($provenance['pending_journal'] ?? null)
            ? self::stringKeyMap($provenance['pending_journal'])
            : [];
        $journalCommandId = is_string($pendingJournal['command_id'] ?? null)
            ? $pendingJournal['command_id']
            : (is_string($provenance['journal_command_id'] ?? null) ? $provenance['journal_command_id'] : null);
        $journalState = is_string($pendingJournal['state'] ?? null)
            ? $pendingJournal['state']
            : (is_string($provenance['journal_state'] ?? null) ? $provenance['journal_state'] : null);
        $committedPosition = is_int($provenance['committed_position'] ?? null)
            ? $provenance['committed_position']
            : null;

        $from = is_int($selector['from_utf16'] ?? null) ? $selector['from_utf16'] : 0;
        $to = is_int($selector['to_utf16'] ?? null) ? $selector['to_utf16'] : $from;
        $parentId = is_string($selector['parentId'] ?? null) ? $selector['parentId'] : null;
        $conflicted = (bool) ($selector['conflicted'] ?? false);
        $conflictReason = is_string($selector['conflict_reason'] ?? null) ? $selector['conflict_reason'] : null;
        $conflictWithId = is_string($selector['conflict_with_id'] ?? null) ? $selector['conflict_with_id'] : null;

        $beforeRaw = $payload['before'] ?? '';
        $afterRaw = $payload['after'] ?? '';
        $before = is_string($beforeRaw) ? $beforeRaw : json_encode($beforeRaw, JSON_THROW_ON_ERROR);
        $after = is_string($afterRaw) ? $afterRaw : json_encode($afterRaw, JSON_THROW_ON_ERROR);

        return new TextSuggestion(
            id: $values['id'],
            groupId: $values['group_id'],
            proposalId: $values['proposal_id'],
            documentId: (string) $values['document_id'],
            generation: (int) $values['generation'],
            basePosition: (int) $values['base_position'],
            schemaVersion: (int) $values['schema_version'],
            authorSubject: $values['author_subject'],
            kind: $values['kind'],
            targetBlockId: $values['target_block_id'],
            from: $from,
            to: $to,
            parentId: $parentId,
            conflicted: $conflicted,
            before: $before,
            after: $after,
            state: $values['state'],
            version: (int) $values['version'],
            receiptId: $values['receipt_id'],
            decidedBySubject: $values['decided_by_subject'],
            decidedAt: $values['decided_at'] === null ? null : new DateTimeImmutable($values['decided_at'], new DateTimeZone('UTC')),
            targetSelector: $selector,
            payload: $payload,
            conflictReason: $conflictReason,
            conflictWithId: $conflictWithId,
            journalCommandId: $journalCommandId,
            journalState: $journalState,
            committedPosition: $committedPosition,
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeyMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
