<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Ci;

use Vaulter\Http\Problems\ProblemCatalog;

/**
 * Renders the part of `docs/reference/errors.md` that belongs to one module from the live
 * {@see ProblemCatalog}, so the page cannot drift from the codes, statuses, retry hints,
 * receipt policies and English texts the API actually answers with.
 *
 * The package problem-catalogue tests compare the page with this block and rewrite it when
 * `VAULTER_UPDATE_DOCS=1` is set (`composer docs:errors`).
 */
final readonly class ErrorsReferenceBlock
{
    public const string PAGE = __DIR__.'/../../docs/reference/errors.md';

    public function __construct(private ProblemCatalog $catalog, private string $module) {}

    public function start(): string
    {
        return '<!-- editor-pro:errors:'.$this->module.':start -->';
    }

    public function end(): string
    {
        return '<!-- editor-pro:errors:'.$this->module.':end -->';
    }

    /** The generated block, markers included. */
    public function render(): string
    {
        $summary = [
            '| Code | HTTP | Retryable | Receipt | Exception |',
            '|---|---|---|---|---|',
        ];
        $sections = [];
        foreach ($this->catalog->all() as $code => $entry) {
            if ($entry['module'] !== $this->module) {
                continue;
            }
            $summary[] = sprintf(
                '| [`%s`](#%s) | %d | `%s` | `%s` | %s |',
                $code,
                $code,
                $entry['status'],
                $entry['retryable']->value,
                $entry['receipt']->value,
                $entry['exception'] === null ? '—' : '`'.$entry['exception'].'`',
            );

            $title = $this->catalog->title($code, 'en');
            $detail = $this->catalog->detail($code, [], 'en');
            $sections[] = '#### `'.$code."`\n\n".($title === null ? '' : '**'.$title.'**'.($detail === null ? '' : ' — '.$detail));
        }

        return $this->start()."\n".implode("\n", $summary)."\n\n".implode("\n\n", $sections)."\n".$this->end();
    }

    /** `$page` with this module's block replaced; null when the markers are missing. */
    public function apply(string $page): ?string
    {
        $start = strpos($page, $this->start());
        $end = strpos($page, $this->end());
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($page, 0, $start).$this->render().substr($page, $end + strlen($this->end()));
    }
}
