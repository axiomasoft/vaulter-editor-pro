<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Repositories;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Packman\Attributes\Internal;
use Vaulter\Documents\Contracts\RevisionPruneProtection;
use Vaulter\EditorPro\Review\Configuration\ReviewOptions;
use Vaulter\EditorPro\Review\Data\RevisionPinData;
use Vaulter\EditorPro\Review\Enums\PinReason;
use Vaulter\EditorPro\Review\Models\DocumentRevisionPin;
use Vaulter\Support\Identity;

/** Persistence repository for `v_document_revision_pins`. */
#[Internal]
final readonly class RevisionPinRepository implements RevisionPruneProtection
{
    public function __construct(private ReviewLookup $lookup, private ReviewOptions $options) {}

    public function protectedRevisionIds(string $documentId): array
    {
        if (! $this->options->enforceRetention) {
            return [];
        }

        return array_map(
            Identity::stringify(...),
            DocumentRevisionPin::query()->where('document_id', $documentId)
                ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))
                ->pluck('revision_id')
                ->all(),
        );
    }

    public function upsert(
        string|int $revisionId,
        string|int $documentId,
        string|int $tenantId,
        PinReason $reason,
        int $sourceGeneration,
        int $sourcePosition,
        string $pinnedBySubject,
        ?DateTimeImmutable $expiresAt = null,
    ): RevisionPinData {
        $now = Carbon::now()->toImmutable();
        $existing = $this->findModel($revisionId, $documentId);

        if ($existing instanceof DocumentRevisionPin) {
            $existing->forceFill([
                'pin_reason' => $reason->value,
                'source_generation' => $sourceGeneration,
                'source_position' => $sourcePosition,
                'pinned_by_subject' => $pinnedBySubject,
                'pinned_at' => $now,
                'expires_at' => $expiresAt !== null ? Carbon::instance($expiresAt)->toImmutable() : null,
            ])->save();

            return $this->toData($existing->refresh());
        }

        $pin = new DocumentRevisionPin;
        $pin->forceFill([
            'revision_id' => $revisionId,
            'document_id' => $documentId,
            'tenant_id' => $tenantId,
            'pin_reason' => $reason->value,
            'source_generation' => $sourceGeneration,
            'source_position' => $sourcePosition,
            'pinned_by_subject' => $pinnedBySubject,
            'pinned_at' => $now,
            'expires_at' => $expiresAt !== null ? Carbon::instance($expiresAt)->toImmutable() : null,
        ]);
        $pin->save();

        return $this->toData($pin->refresh());
    }

    public function find(string|int $revisionId, string|int $documentId): ?RevisionPinData
    {
        $model = $this->findModel($revisionId, $documentId);

        return $model instanceof DocumentRevisionPin ? $this->toData($model) : null;
    }

    public function findActive(string|int $revisionId, string|int $documentId, ?DateTimeImmutable $now = null): ?RevisionPinData
    {
        $pin = $this->find($revisionId, $documentId);
        if ($pin === null || $pin->isExpired($now)) {
            return null;
        }

        return $pin;
    }

    /** @return list<RevisionPinData> newest first */
    public function listForDocument(string|int $documentId, bool $includeExpired = false): array
    {
        if (! $this->lookup->fitsKey($documentId)) {
            return [];
        }

        $query = DocumentRevisionPin::query()
            ->where('document_id', $documentId)
            ->orderByDesc('pinned_at');

        if (! $includeExpired) {
            $now = Carbon::now();
            $query->where(static function ($nested) use ($now): void {
                $nested->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
        }

        return array_values($query->get()
            ->map(fn (DocumentRevisionPin $pin): RevisionPinData => $this->toData($pin))
            ->all());
    }

    public function delete(string|int $revisionId, string|int $documentId): bool
    {
        $model = $this->findModel($revisionId, $documentId);
        if (! $model instanceof DocumentRevisionPin) {
            return false;
        }

        return (bool) $model->delete();
    }

    public function deleteExpired(string|int $documentId): int
    {
        if (! $this->lookup->fitsKey($documentId)) {
            return 0;
        }

        return DocumentRevisionPin::query()
            ->where('document_id', $documentId)
            ->where('expires_at', '<=', Carbon::now())
            ->delete();
    }

    private function findModel(string|int $revisionId, string|int $documentId): ?DocumentRevisionPin
    {
        if (! $this->lookup->fitsKey($revisionId) || ! $this->lookup->fitsKey($documentId)) {
            return null;
        }

        return DocumentRevisionPin::query()
            ->where('revision_id', $revisionId)
            ->where('document_id', $documentId)
            ->first();
    }

    private function toData(DocumentRevisionPin $model): RevisionPinData
    {
        return new RevisionPinData(
            revisionId: Identity::stringify($model->revision_id),
            documentId: Identity::stringify($model->document_id),
            pinReason: PinReason::from($model->pin_reason),
            sourceGeneration: $model->source_generation,
            sourcePosition: $model->source_position,
            pinnedBySubject: $model->pinned_by_subject,
            pinnedAt: $model->pinned_at->toDateTimeImmutable(),
            expiresAt: $model->expires_at?->toDateTimeImmutable(),
        );
    }
}
