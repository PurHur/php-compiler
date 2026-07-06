<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING paths for glob() (php-src ext/standard/dir.c; #16970).
 */
final class VmFsGlobFailure
{
    public const INVALID_FLAGS_MESSAGE =
        'glob(): At least one of the passed flags is invalid or not supported on this platform';

    public static function hasValidFlags(int $flags): bool
    {
        return 0 === ($flags & ~StdlibConstants::GLOB_AVAILABLE_FLAGS);
    }

    public static function warnInvalidFlags(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            self::INVALID_FLAGS_MESSAGE,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame,
            $frame->callSiteLine
        );
    }
}
