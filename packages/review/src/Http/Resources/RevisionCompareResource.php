<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\RevisionCompareData;

/** JSON representation of two fixed document versions for isolated structural diff comparison. */
#[Internal]
final class RevisionCompareResource extends JsonResource
{
    public function __construct(RevisionCompareData $compare)
    {
        parent::__construct($compare);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var RevisionCompareData $compare */
        $compare = $this->resource;

        return [
            'from' => [
                'meta' => RevisionPreviewResource::formatMeta($compare->from),
                'pin' => RevisionPreviewResource::formatPin($compare->fromPin, $request),
                'content' => RevisionPreviewResource::sanitizeContent($compare->fromContent),
            ],
            'to' => [
                'meta' => RevisionPreviewResource::formatMeta($compare->to),
                'pin' => RevisionPreviewResource::formatPin($compare->toPin, $request),
                'content' => RevisionPreviewResource::sanitizeContent($compare->toContent),
            ],
        ];
    }
}
