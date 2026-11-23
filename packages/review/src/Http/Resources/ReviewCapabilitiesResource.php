<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Packman\Attributes\Internal;
use Vaulter\EditorPro\Review\Data\ReviewCapabilitiesData;

/** JSON representation of review structural capabilities. */
#[Internal]
final class ReviewCapabilitiesResource extends JsonResource
{
    public function __construct(ReviewCapabilitiesData $capabilities)
    {
        parent::__construct($capabilities);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var ReviewCapabilitiesData $capabilities */
        $capabilities = $this->resource;

        return $capabilities->toArray();
    }
}
