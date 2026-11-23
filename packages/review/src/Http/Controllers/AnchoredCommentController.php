<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Http\Support\NodeRequestContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\AnchoredCommentData;
use Vaulter\EditorPro\Review\Enums\CommentFilterStatus;
use Vaulter\EditorPro\Review\Http\Requests\AddAnchoredCommentRequest;
use Vaulter\EditorPro\Review\Http\Requests\ReanchorCommentRequest;
use Vaulter\EditorPro\Review\Http\Resources\AnchoredCommentResource;
use Vaulter\EditorPro\Review\Http\Support\ReviewPreconditions;
use Vaulter\EditorPro\Review\ReviewManager;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\Http\Resources\PageResponse;

/** Endpoints for generation-scoped range comments, CAS resolve/reopen, and reanchoring. */
#[Internal]
final readonly class AnchoredCommentController
{
    public function __construct(
        private NodeRequestContext $contexts,
        private Vaulter $vaulter,
        private ReviewManager $review,
        private ReviewPreconditions $preconditions,
    ) {}

    public function index(Request $request, string $node): JsonResponse
    {
        $statuses = array_map(static fn (CommentFilterStatus $s): string => $s->value, CommentFilterStatus::cases());
        /** @var array{status?: string} $validated */
        $validated = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', Rule::in($statuses)],
        ])->validate();
        $status = CommentFilterStatus::from($validated['status'] ?? CommentFilterStatus::All->value);

        $perPage = PageResponse::perPage($request);
        $cursor = PageResponse::cursor($request);

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node));
        $page = $this->review->comments($drive)->list($node, $status, new ListOptions(cursor: $cursor, perPage: $perPage));

        return PageResponse::make(
            $page,
            static fn (AnchoredCommentData $item): array => (new AnchoredCommentResource($item))->toArray($request),
            $perPage,
        );
    }

    public function store(AddAnchoredCommentRequest $request, string $node): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $created = $this->review->comments($drive)->add($node, $request->toData());
        $resource = new AnchoredCommentResource($created);

        return $resource->response()->setStatusCode(201)->setEtag($resource->etag());
    }

    public function resolve(Request $request, string $node, string $comment): JsonResponse
    {
        $context = $this->contexts->resolve($request, $node);
        $expectedVersion = $this->preconditions->expectedCommentVersion($request, $context, $node, $comment, delete: false);

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $context, withIdempotency: true);
        $updated = $this->review->comments($drive)->resolve($node, $comment, $expectedVersion);
        $resource = new AnchoredCommentResource($updated);

        return $resource->response()->setEtag($resource->etag());
    }

    public function reopen(Request $request, string $node, string $comment): JsonResponse
    {
        $context = $this->contexts->resolve($request, $node);
        $expectedVersion = $this->preconditions->expectedCommentVersion($request, $context, $node, $comment, delete: false);

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $context, withIdempotency: true);
        $updated = $this->review->comments($drive)->reopen($node, $comment, $expectedVersion);
        $resource = new AnchoredCommentResource($updated);

        return $resource->response()->setEtag($resource->etag());
    }

    public function reanchor(ReanchorCommentRequest $request, string $node, string $comment): JsonResponse
    {
        $context = $this->contexts->resolve($request, $node);
        $expectedAnchorVersion = $this->preconditions->expectedAnchorVersion($request, $context, $node, $comment);

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $context, withIdempotency: true);
        $updated = $this->review->comments($drive)->reanchor($node, $comment, $request->toData(), $expectedAnchorVersion);
        $resource = new AnchoredCommentResource($updated);

        return $resource->response()->setEtag($resource->etag());
    }

    public function destroy(Request $request, string $node, string $comment): JsonResponse
    {
        $context = $this->contexts->resolve($request, $node);
        $expectedVersion = $this->preconditions->expectedCommentVersion($request, $context, $node, $comment, delete: true);

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $context, withIdempotency: true);
        $this->review->comments($drive)->delete($node, $comment, $expectedVersion);

        return response()->json(null, 204);
    }
}
