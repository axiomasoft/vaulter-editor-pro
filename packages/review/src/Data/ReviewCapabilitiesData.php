<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Data;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/**
 * Capability matrix exposed by review services so UI controls can hide unsupported structures
 * and API guards can reject unsupported candidates prior to write.
 */
#[Api(since: '1.0')]
final readonly class ReviewCapabilitiesData
{
    /**
     * @param  list<string>  $supportedKinds
     * @param  list<string>  $textKinds
     * @param  list<string>  $structuralKinds
     * @param  list<string>  $nodeTypeChangeTypes
     * @param  list<string>  $allowedMarks
     * @param  array<string, list<string>>  $allowedAttributes
     * @param  list<string>  $listOperations
     * @param  list<string>  $tableOperations
     */
    #[Internal]
    public function __construct(
        public array $supportedKinds,
        public array $textKinds,
        public array $structuralKinds,
        public array $nodeTypeChangeTypes,
        public string $tableMode,
        public array $allowedMarks,
        public array $allowedAttributes,
        public array $listOperations,
        public array $tableOperations,
        public string $overlapPolicy = 'keep_open_with_conflict',
        public ?string $documentType = null,
        public ?string $writeMode = null,
        public bool $journalSupported = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'supported_kinds' => $this->supportedKinds,
            'text_kinds' => $this->textKinds,
            'structural_kinds' => $this->structuralKinds,
            'node_type_change_types' => $this->nodeTypeChangeTypes,
            'table_mode' => $this->tableMode,
            'allowed_marks' => $this->allowedMarks,
            'allowed_attributes' => $this->allowedAttributes,
            'list_operations' => $this->listOperations,
            'table_operations' => $this->tableOperations,
            'overlap_policy' => $this->overlapPolicy,
            'document_type' => $this->documentType,
            'write_mode' => $this->writeMode,
            'journal_supported' => $this->journalSupported,
        ];
    }
}
