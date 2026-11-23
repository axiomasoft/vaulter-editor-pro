<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Repositories;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use stdClass;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
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
        return $this->query($connection)->where('document_id', $documentId)->where('generation', $generation)
            ->whereIn('kind', ['insert', 'delete', 'replace']);
    }

    /** @return list<TextSuggestion> */
    public function read(Builder $query): array
    {
        return array_values($query->orderBy('id')->get()->map(self::data(...))->all());
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

    public static function selector(int $from, int $to, ?string $parentId, bool $conflicted = false): string
    {
        return json_encode([
            'schema' => 1,
            'from_utf16' => $from,
            'to_utf16' => $to,
            'parentId' => $parentId,
            'conflicted' => $conflicted,
        ], JSON_THROW_ON_ERROR);
    }

    private static function data(stdClass $row): TextSuggestion
    {
        /** @var array{id: string, group_id: string, proposal_id: string|null, document_id: int|string, generation: int|string, base_position: int|string, schema_version: int|string, author_subject: string, kind: string, target_block_id: string, target_selector: string, payload: string, state: string, version: int|string, receipt_id: string|null, decided_by_subject: string|null, decided_at: string|null} $values */
        $values = (array) $row;
        /** @var array{from_utf16: int, to_utf16: int, parentId: string|null, conflicted: bool} $selector */
        $selector = json_decode($values['target_selector'], true, flags: JSON_THROW_ON_ERROR);
        /** @var array{before: string, after: string} $payload */
        $payload = json_decode($values['payload'], true, flags: JSON_THROW_ON_ERROR);

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
            from: $selector['from_utf16'],
            to: $selector['to_utf16'],
            parentId: $selector['parentId'],
            conflicted: $selector['conflicted'],
            before: $payload['before'],
            after: $payload['after'],
            state: $values['state'],
            version: (int) $values['version'],
            receiptId: $values['receipt_id'],
            decidedBySubject: $values['decided_by_subject'],
            decidedAt: $values['decided_at'] === null ? null : new DateTimeImmutable($values['decided_at'], new \DateTimeZone('UTC')),
        );
    }
}
