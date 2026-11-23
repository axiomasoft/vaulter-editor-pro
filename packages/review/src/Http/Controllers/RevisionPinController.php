<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Packman\Attributes\Internal;
use Vaulter\Drive\Http\Support\NodeRequestContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\RevisionPinData;
use Vaulter\EditorPro\Review\Http\Requests\PinRevisionRequest;
use Vaulter\EditorPro\Review\Http\Resources\RevisionCompareResource;
use Vaulter\EditorPro\Review\Http\Resources\RevisionPinResource;
use Vaulter\EditorPro\Review\Http\Resources\RevisionPreviewResource;
use Vaulter\EditorPro\Review\ReviewManager;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;

/** Endpoints for revision pins, immutable revision previews, and two-version compare. */
#[Internal]
final readonly class RevisionPinController
{
    public function __construct(
        private NodeRequestContext $contexts,
        private Vaulter $vaulter,
        private ReviewManager $review,
    ) {}

    public function index(Request $request, string $node): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node));
        $includeExpired = $request->boolean('include_expired');
        $pins = $this->review->pins($drive)->list($node, $includeExpired);

        return response()->json([
            'data' => array_map(
                static fn (RevisionPinData $pin): array => (new RevisionPinResource($pin))->toArray($request),
                $pins,
            ),
        ]);
    }

    public function store(PinRevisionRequest $request, string $node, string $revision): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $pin = $this->review->pins($drive)->pin($node, $revision, $request->toData());

        return (new RevisionPinResource($pin))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $node, string $revision): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $this->review->pins($drive)->unpin($node, $revision, $request->boolean('release_retention'));

        return response()->json(null, 204);
    }

    public function preview(Request $request, string $node, string $revision): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node));
        $preview = $this->review->pins($drive)->preview($node, $revision);

        return (new RevisionPreviewResource($preview))->response();
    }

    public function compare(Request $request, string $node): JsonResponse
    {
        /** @var array{from: string, to?: string|null} $validated */
        $validated = Validator::make($request->query(), [
            'from' => ['required', 'string', 'max:191'],
            'to' => ['nullable', 'string', 'max:191'],
        ])->validate();

        $from = $validated['from'];
        $rawTo = $validated['to'] ?? null;
        $to = ($rawTo === null || $rawTo === '' || $rawTo === 'working') ? null : $rawTo;

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node));
        $compare = $this->review->pins($drive)->compare($node, $from, $to);

        return (new RevisionCompareResource($compare))->response();
    }
}
