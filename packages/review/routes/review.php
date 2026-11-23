<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesOptions;
use Vaulter\EditorPro\Review\Http\Controllers\AnchoredCommentController;
use Vaulter\EditorPro\Review\Http\Controllers\RevisionPinController;
use Vaulter\EditorPro\Review\Http\Controllers\SuggestionController;

$routes = app(RoutesOptions::class);
$id = '[A-Za-z0-9_-]+';

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware($routes->authenticatedMiddleware())
    ->where(['node' => $id, 'revision' => $id, 'comment' => $id, 'suggestion' => $id, 'group' => $id])
    ->group(static function (): void {
        Route::get('review/documents/{node}/pins', [RevisionPinController::class, 'index'])
            ->name('review.pins.index');
        Route::post('review/documents/{node}/revisions/{revision}/pin', [RevisionPinController::class, 'store'])
            ->name('review.pins.store')
            ->middleware('vaulter.idempotency');
        Route::delete('review/documents/{node}/revisions/{revision}/pin', [RevisionPinController::class, 'destroy'])
            ->name('review.pins.destroy')
            ->middleware('vaulter.idempotency');

        Route::get('review/documents/{node}/revisions/{revision}/preview', [RevisionPinController::class, 'preview'])
            ->name('review.revisions.preview');
        Route::get('review/documents/{node}/compare', [RevisionPinController::class, 'compare'])
            ->name('review.revisions.compare');

        Route::get('review/nodes/{node}/comments', [AnchoredCommentController::class, 'index'])
            ->name('review.comments.index');
        Route::post('review/nodes/{node}/comments', [AnchoredCommentController::class, 'store'])
            ->name('review.comments.store')
            ->middleware('vaulter.idempotency');
        Route::post('review/nodes/{node}/comments/{comment}/resolve', [AnchoredCommentController::class, 'resolve'])
            ->name('review.comments.resolve')
            ->middleware('vaulter.idempotency');
        Route::post('review/nodes/{node}/comments/{comment}/reopen', [AnchoredCommentController::class, 'reopen'])
            ->name('review.comments.reopen')
            ->middleware('vaulter.idempotency');
        Route::post('review/nodes/{node}/comments/{comment}/reanchor', [AnchoredCommentController::class, 'reanchor'])
            ->name('review.comments.reanchor')
            ->middleware('vaulter.idempotency');
        Route::delete('review/nodes/{node}/comments/{comment}', [AnchoredCommentController::class, 'destroy'])
            ->name('review.comments.destroy')
            ->middleware('vaulter.idempotency');

        Route::get('review/documents/{node}/suggestions/capabilities', [SuggestionController::class, 'capabilities'])
            ->name('review.suggestions.capabilities');
        Route::get('review/documents/{node}/suggestions', [SuggestionController::class, 'index'])
            ->name('review.suggestions.index');
        Route::post('review/documents/{node}/suggestions', [SuggestionController::class, 'store'])
            ->name('review.suggestions.store')
            ->middleware('vaulter.idempotency');
        Route::post('review/documents/{node}/suggestions/{suggestion}/accept', [SuggestionController::class, 'accept'])
            ->name('review.suggestions.accept')
            ->middleware('vaulter.idempotency');
        Route::post('review/documents/{node}/suggestions/{suggestion}/reject', [SuggestionController::class, 'reject'])
            ->name('review.suggestions.reject')
            ->middleware('vaulter.idempotency');
        Route::post('review/documents/{node}/suggestions/groups/{group}/accept', [SuggestionController::class, 'acceptGroup'])
            ->name('review.suggestions.groups.accept')
            ->middleware('vaulter.idempotency');
        Route::post('review/documents/{node}/suggestions/groups/{group}/reject', [SuggestionController::class, 'rejectGroup'])
            ->name('review.suggestions.groups.reject')
            ->middleware('vaulter.idempotency');
    });
