<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING paths for copy() (php-src ext/standard/file.c; #11703).
 */
final class VmCopyFailure
{
    public const DIRECTORY_SOURCE_MESSAGE = 'The first argument to copy() function cannot be a directory';

    public static function warnDirectorySource(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            self::DIRECTORY_SOURCE_MESSAGE,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame,
            $frame->callSiteLine
        );
    }
}
