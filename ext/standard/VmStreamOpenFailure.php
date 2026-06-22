<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING when fopen/file read fails for path-based builtins (php-src streams.c; #10625, #10441).
 */
final class VmStreamOpenFailure
{
    public static function warnFailedToOpen(Frame $frame, string $function, string $path): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $message = \sprintf(
            '%s(%s): Failed to open stream: No such file or directory',
            $function,
            $path
        );
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
