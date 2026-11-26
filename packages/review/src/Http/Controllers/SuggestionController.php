<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Http\Support\NodeRequestContext;
use Vaulter\Drive\Vaulter;
use Vaulter\EditorPro\Review\Data\TextSuggestion;
use Vaulter\EditorPro\Review\Http\Requests\DecideSuggestionRequest;
use Vaulter\EditorPro\Review\Http\Requests\SaveSuggestionRequest;
use Vaulter\EditorPro\Review\Http\Resources\ReviewCapabilitiesResource;
use Vaulter\EditorPro\Review\Http\Resources\SuggestionResource;
use Vaulter\EditorPro\Review\Http\Support\SuggestionPreconditions;
use Vaulter\EditorPro\Review\ReviewManager;
use Vaulter\EditorPro\Review\Support\ReviewDriveHandle;
use Vaulter\Http\Resources\PageResponse;

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
        $includeStale = $request->boolean('include_stale_generations');
        if ($request->query->has('per_page') || $request->query->has('cursor') || $request->query->has('open_only')) {
            $options = new ListOptions(perPage: PageResponse::perPage($request), cursor: PageResponse::cursor($request));
            $page = $this->review->suggestions($drive)->page($node, $options, is_string($proposalId) && $proposalId !== '' ? $proposalId : null, $includeStale, $request->boolean('open_only'));

            return PageResponse::make($page, static fn (TextSuggestion $item): array => (new SuggestionResource($item))->toArray($request), $options->perPage);
        }
        $items = $this->review->suggestions($drive)->list(
            $node,
            is_string($proposalId) && $proposalId !== '' ? $proposalId : null,
            $includeStale,
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
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $tags = SuggestionPreconditions::tags($request);
        $saved = $this->review->suggestions($drive)->save($node, $request->toData(), $request->expectedVersion(), $tags);

        return (new SuggestionResource($saved))
            ->response()
            ->setStatusCode($saved->version === 1 ? 201 : 200)
            ->setEtag($saved->etag());
    }

    public function accept(DecideSuggestionRequest $request, string $node, string $suggestion): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $resolved = $this->review->suggestions($drive)->accept(
            $node,
            $suggestion,
            $request->generation(),
            $request->expectedVersion(),
            $request->commandId(),
            SuggestionPreconditions::tags($request),
        );

        return (new SuggestionResource($resolved))
            ->response()
            ->setStatusCode($resolved->isPendingJournal() ? 202 : 200)
            ->setEtag($resolved->etag());
    }

    public function reject(DecideSuggestionRequest $request, string $node, string $suggestion): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $resolved = $this->review->suggestions($drive)->reject(
            $node,
            $suggestion,
            $request->generation(),
            $request->expectedVersion(),
            $request->commandId(),
            SuggestionPreconditions::tags($request),
        );

        return (new SuggestionResource($resolved))
            ->response()
            ->setStatusCode($resolved->isPendingJournal() ? 202 : 200)
            ->setEtag($resolved->etag());
    }

    public function acceptGroup(DecideSuggestionRequest $request, string $node, string $group): JsonResponse
    {
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $items = $this->review->suggestions($drive)->acceptGroup(
            $node,
            $group,
            $request->generation(),
            $request->expectedVersions(),
            $request->commandId(),
            SuggestionPreconditions::tags($request),
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
        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $items = $this->review->suggestions($drive)->rejectGroup(
            $node,
            $group,
            $request->generation(),
            $request->expectedVersions(),
            $request->commandId(),
            SuggestionPreconditions::tags($request),
        );

        $pending = $items !== [] && $items[0]->isPendingJournal();

        return response()->json([
            'data' => array_map(
                static fn (TextSuggestion $item): array => (new SuggestionResource($item))->toArray($request),
                $items,
            ),
        ], $pending ? 202 : 200);
    }

    public function bulkAccept(Request $request, string $node): JsonResponse
    {
        return $this->handleBulk($request, $node, 'accepted');
    }

    public function bulkReject(Request $request, string $node): JsonResponse
    {
        return $this->handleBulk($request, $node, 'rejected');
    }

    /** @param 'accepted'|'rejected' $decision */
    private function handleBulk(Request $request, string $node, string $decision): JsonResponse
    {
        $generation = $request->integer('generation');
        if ($generation < 1) {
            abort(422, 'The generation must be at least 1.');
        }
        $scope = $request->string('scope')->toString();
        if (! in_array($scope, ['all', 'by_user', 'in_range'], true)) {
            abort(422, 'Invalid bulk scope.');
        }
        $authorSubject = $request->string('author_subject')->toString();
        if ($scope === 'by_user' && $authorSubject === '') {
            abort(422, 'author_subject is required for by_user scope.');
        }
        $from = $request->integer('from', -1);
        $to = $request->integer('to', -1);
        if ($scope === 'in_range' && ($from < 0 || $to < 0)) {
            abort(422, 'from and to are required for in_range scope.');
        }
        $rawCommandId = $request->input('command_id');
        $commandId = is_string($rawCommandId) && $rawCommandId !== '' ? $rawCommandId : null;

        $drive = ReviewDriveHandle::fromContext($this->vaulter, $this->contexts->resolve($request, $node), withIdempotency: true);
        $ops = $this->review->suggestions($drive);

        $items = match ($scope) {
            'by_user' => $decision === 'accepted'
                ? $ops->acceptByUser($node, $generation, $authorSubject, $commandId)
                : $ops->rejectByUser($node, $generation, $authorSubject, $commandId),
            'in_range' => $decision === 'accepted'
                ? $ops->acceptInRange($node, $generation, $from, $to, $commandId)
                : $ops->rejectInRange($node, $generation, $from, $to, $commandId),
            default => $decision === 'accepted'
                ? $ops->acceptAll($node, $generation, $commandId)
                : $ops->rejectAll($node, $generation, $commandId),
        };

        $pending = $items !== [] && $items[0]->isPendingJournal();

        return response()->json([
            'data' => array_map(
                static fn (TextSuggestion $item): array => (new SuggestionResource($item))->toArray($request),
                $items,
            ),
        ], $pending ? 202 : 200);
    }
}
