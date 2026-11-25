<?php

declare(strict_types=1);

use Packman\Errors\Data\ProblemDefinition;
use Vaulter\EditorPro\Exchange\Errors\ExchangeErrorCode;
use Vaulter\EditorPro\Exchange\Errors\ExchangeProblemDefinitions;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\Http\Problems\ProblemCatalog;
use Vaulter\Http\Problems\Retryable;

it('registers every exchange error code with one status, category and exception', function (): void {
    $catalog = app(ProblemCatalog::class);
    /** @var array<string, ProblemDefinition> $definitions */
    $definitions = [];
    foreach ((new ExchangeProblemDefinitions)->definitions() as $definition) {
        $definitions[$definition->code->value()] = $definition;
    }

    expect(array_keys($definitions))->toBe(array_map(static fn (ExchangeErrorCode $code): string => $code->value, ExchangeErrorCode::cases()));

    foreach (ExchangeErrorCode::cases() as $code) {
        $slug = $code->problemSlug();
        $spec = $catalog->spec($slug);

        expect($spec)->not->toBeNull("{$slug} is not in the HTTP problem catalogue")
            ->and($catalog->module($slug))->toBe('exchange')
            ->and($spec?->status)->toBe($definitions[$code->value]->defaultStatus)
            ->and($spec?->retryable === Retryable::Never)->toBe(! $definitions[$code->value]->retryable)
            ->and($spec?->exception)->not->toBeNull();

        $exception = exchangeExceptionFor($code);
        expect($exception->exchangeCode())->toBe($code)
            ->and($exception->problemCode())->toBe($slug)
            ->and($exception->httpStatus())->toBe($spec?->status)
            ->and($exception->errorDescriptor()->category)->toBe($definitions[$code->value]->category)
            ->and($exception->errorDescriptor()->retryable)->toBe($definitions[$code->value]->retryable);
    }
});

it('ships an English and a Russian title and detail for every exchange problem', function (string $locale): void {
    $catalog = app(ProblemCatalog::class);

    foreach (ExchangeErrorCode::cases() as $code) {
        expect($catalog->title($code->problemSlug(), $locale))->toBeString("{$code->problemSlug()} has no {$locale} title")
            ->and($catalog->detail($code->problemSlug(), [], $locale))->toBeString("{$code->problemSlug()} has no {$locale} detail");
    }
})->with(['en', 'ru']);

/** Every factory must produce the code it is named after. */
function exchangeExceptionFor(ExchangeErrorCode $code): ExchangeException
{
    return match ($code) {
        ExchangeErrorCode::JobNotFound => ExchangeException::jobNotFound('job'),
        ExchangeErrorCode::IdempotencyConflict => ExchangeException::idempotencyConflict(),
        ExchangeErrorCode::InvalidCommand => ExchangeException::invalidCommand('Invalid.'),
        ExchangeErrorCode::UnsafeArchive => ExchangeException::unsafeArchive('zip_slip', '../x'),
        ExchangeErrorCode::ZipLimitExceeded => ExchangeException::zipLimitExceeded('max_entries'),
        ExchangeErrorCode::ProfileBlocked => ExchangeException::profileBlocked(),
        ExchangeErrorCode::JobNotReady => ExchangeException::jobNotReady('running'),
        ExchangeErrorCode::StalePreview => ExchangeException::stalePreview(),
        ExchangeErrorCode::AlreadyApplied => ExchangeException::alreadyApplied(),
        ExchangeErrorCode::SourceUnavailable => ExchangeException::sourceUnavailable(),
        ExchangeErrorCode::RuntimeFailed => ExchangeException::runtimeFailed(),
        ExchangeErrorCode::LeaseLost => ExchangeException::leaseLost(),
        ExchangeErrorCode::FormatDisabled => ExchangeException::formatDisabled('pdf'),
        ExchangeErrorCode::UnsupportedDocument => ExchangeException::unsupportedDocument(),
    };
}
