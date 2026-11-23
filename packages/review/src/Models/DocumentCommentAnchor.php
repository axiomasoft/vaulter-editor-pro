<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * Internal Eloquent representation of `v_document_comment_anchors`.
 *
 * @property string|int $id
 * @property string|int $tenant_id
 * @property string|int $comment_id
 * @property string|int $document_id
 * @property int $generation
 * @property int $source_position
 * @property int $schema_version
 * @property string $block_id
 * @property string $selector_kind
 * @property array<string, mixed> $selector_payload
 * @property string $quote_text
 * @property string $anchor_state
 * @property int $version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
#[Internal]
final class DocumentCommentAnchor extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public const string AGGREGATE = 'review.comment_anchor';

    protected $guarded = [];

    public static function tableName(): string
    {
        return app(DatabaseOptions::class)->table('document_comment_anchors');
    }

    public function getTable(): string
    {
        return self::tableName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'source_position' => 'integer',
            'schema_version' => 'integer',
            'selector_payload' => 'array',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
