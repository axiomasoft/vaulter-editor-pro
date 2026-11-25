<?php

declare(strict_types=1);

use Packman\Errors\Data\ProblemDefinition;
use Vaulter\EditorPro\Ci\ErrorsReferenceBlock;
use Vaulter\EditorPro\Review\Errors\ReviewErrorCode;
use Vaulter\EditorPro\Review\Errors\ReviewProblemDefinitions;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Http\Problems\Retryable;

it('registers every review error code with one status, category and exception', function (): void {
    $catalog = app(ProblemCatalog::class);
    /** @var array<string, ProblemDefinition> $definitions */
    $definitions = [];
    foreach ((new ReviewProblemDefinitions)->definitions() as $definition) {
        $definitions[$definition->code->value()] = $definition;
    }

    expect(array_keys($definitions))->toBe(array_map(static fn (ReviewErrorCode $code): string => $code->value, ReviewErrorCode::cases()));

    foreach (ReviewErrorCode::cases() as $code) {
        $slug = $code->problemSlug();
        $spec = $catalog->spec($slug);

        expect($spec)->not->toBeNull("{$slug} is not in the HTTP problem catalogue")
            ->and($catalog->module($slug))->toBe('review')
            ->and($spec?->status)->toBe($definitions[$code->value]->defaultStatus)
            ->and($spec?->retryable === Retryable::Never)->toBe(! $definitions[$code->value]->retryable)
            ->and($spec?->exception)->not->toBeNull();

        $exception = (new ReflectionClass((string) $spec?->exception))->newInstanceWithoutConstructor();
        expect($exception)->toBeInstanceOf(ProblemException::class);
        assert($exception instanceof ProblemException);
        expect($exception->problemCode())->toBe($slug)
            ->and($exception->httpStatus())->toBe($spec?->status);
    }
});

it('ships an English and a Russian title and detail for every review problem', function (string $locale): void {
    $catalog = app(ProblemCatalog::class);

    foreach (ReviewErrorCode::cases() as $code) {
        expect($catalog->title($code->problemSlug(), $locale))->toBeString("{$code->problemSlug()} has no {$locale} title")
            ->and($catalog->detail($code->problemSlug(), [], $locale))->toBeString("{$code->problemSlug()} has no {$locale} detail");
    }
})->with(['en', 'ru']);

it('keeps the review section of docs/reference/errors.md in sync with the catalogue', function (): void {
    $block = new ErrorsReferenceBlock(app(ProblemCatalog::class), 'review');
    $page = (string) file_get_contents(ErrorsReferenceBlock::PAGE);
    $updated = $block->apply($page);

    expect($updated)->not->toBeNull('docs/reference/errors.md lacks the review markers');
    if (getenv('VAULTER_UPDATE_DOCS') === '1' && $updated !== $page) {
        file_put_contents(ErrorsReferenceBlock::PAGE, (string) $updated);
        $page = (string) $updated;
    }

    expect($page)->toBe($updated, 'docs/reference/errors.md is out of date; run composer docs:errors');
});
