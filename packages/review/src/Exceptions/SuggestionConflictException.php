<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Review\Exceptions;

use Packman\Attributes\Api;
use RuntimeException;

/** The selection or its text target must be refreshed before a decision can be made. */
#[Api(since: '1.0')]
final class SuggestionConflictException extends RuntimeException {}
