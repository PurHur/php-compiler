<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING when path stat/lstat fails for filestat builtins (php-src ext/standard/filestat.c; #10548, #10547).
 */
final class VmFilestatFailure
{
    public static function warnPathStatFailed(Frame $frame, string $function, string $path, bool $lstat): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $op = $lstat ? 'Lstat' : 'stat';
        $message = \sprintf('%s(): %s failed for %s', $function, $op, $path);
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
