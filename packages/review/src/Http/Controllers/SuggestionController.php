<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Drive\Http\Support\NodeRequestContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Http\Requests\DecideSuggestionRequest;
use Vaulter\EditorPro\Review\Http\Requests\SaveSuggestionRequest;
use Vaulter\EditorPro\Review\Http\Resources\ReviewCapabilitiesResource;
use Vaulter\EditorPro\Review\Http\Resources\SuggestionResource;
use Vaulter\EditorPro\Review\ReviewManager;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;

/** HTTP endpoints for text and structural review suggestions and capability queries. */
#[Internal]
final readonly class SuggestionController
{
    public function __construct(
        private NodeRequestContext $contexts,
        private Vaulter $vaulter,
        private ReviewManager $review,
    ) {}

    public function capabilities(Request $request, string $node): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node));
        $caps = $this->review->suggestions($drive)->capabilities($node);

        return (new ReviewCapabilitiesResource($caps))->response();
    }

    public function index(Request $request, string $node): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node));
        $proposalId = $request->query('proposal_id');
        $items = $this->review->suggestions($drive)->list(
            $node,
            is_string($proposalId) && $proposalId !== '' ? $proposalId : null,
        );

        return response()->json([
            'data' => array_map(
                static fn (TextSuggestion $item): array => (new SuggestionResource($item))->toArray($request),
                $items,
            ),
        ]);
    }

    public function store(SaveSuggestionRequest $request, string $node): JsonResponse
    {
        // Pass withIdempotency: false so AtomicDocumentMutation runs cleanly on snapshot writes
        // without nested OperationRunner idempotency conflicts.
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: false);
        $saved = $this->review->suggestions($drive)->save($node, $request->toData(), $request->expectedVersion());

        return (new SuggestionResource($saved))
            ->response()
            ->setStatusCode($request->expectedVersion() === 0 ? 201 : 200)
            ->setEtag($saved->etag());
    }

    public function accept(DecideSuggestionRequest $request, string $node, string $suggestion): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: false);
        $resolved = $this->review->suggestions($drive)->accept(
            $node,
            $suggestion,
            $request->generation(),
            $request->expectedVersion(),
            $request->commandId(),
        );

        return (new SuggestionResource($resolved))
            ->response()
            ->setStatusCode($resolved->isPendingJournal() ? 202 : 200)
            ->setEtag($resolved->etag());
    }

    public function reject(DecideSuggestionRequest $request, string $node, string $suggestion): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: false);
        $resolved = $this->review->suggestions($drive)->reject(
            $node,
            $suggestion,
            $request->generation(),
            $request->expectedVersion(),
            $request->commandId(),
        );

        return (new SuggestionResource($resolved))
            ->response()
            ->setStatusCode($resolved->isPendingJournal() ? 202 : 200)
            ->setEtag($resolved->etag());
    }

    public function acceptGroup(DecideSuggestionRequest $request, string $node, string $group): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: false);
        $items = $this->review->suggestions($drive)->acceptGroup(
            $node,
            $group,
            $request->generation(),
            $request->expectedVersions(),
            $request->commandId(),
        );

        $pending = $items !== [] && $items[0]->isPendingJournal();

        return response()->json([
            'data' => array_map(
                static fn (TextSuggestion $item): array => (new SuggestionResource($item))->toArray($request),
                $items,
            ),
        ], $pending ? 202 : 200);
    }

    public function rejectGroup(DecideSuggestionRequest $request, string $node, string $group): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: false);
        $items = $this->review->suggestions($drive)->rejectGroup(
            $node,
            $group,
            $request->generation(),
            $request->expectedVersions(),
            $request->commandId(),
        );

        $pending = $items !== [] && $items[0]->isPendingJournal();

        return response()->json([
            'data' => array_map(
                static fn (TextSuggestion $item): array => (new SuggestionResource($item))->toArray($request),
                $items,
            ),
        ], $pending ? 202 : 200);
    }
}
