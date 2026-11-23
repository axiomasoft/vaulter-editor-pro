<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * Internal Eloquent representation of `v_document_revision_pins`.
 *
 * @property string|int $revision_id
 * @property string|int $document_id
 * @property string|int $tenant_id
 * @property string $pin_reason
 * @property int $source_generation
 * @property int $source_position
 * @property string $pinned_by_subject
 * @property CarbonInterface $pinned_at
 * @property CarbonInterface|null $expires_at
 */
#[Internal]
final class DocumentRevisionPin extends Model
{
    use GuardsWrites;
    use ResolvesVaulterConnection;

    public const bool TIMESTAMPS = false;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'revision_id';

    protected $keyType = 'string';

    protected $guarded = [];

    public static function tableName(): string
    {
        return app(DatabaseOptions::class)->table('document_revision_pins');
    }

    public function getTable(): string
    {
        return self::tableName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_generation' => 'integer',
            'source_position' => 'integer',
            'pinned_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
