<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING paths for preg_* builtins (php-src ext/pcre/php_pcre.c; #11015).
 */
final class VmPregFailure
{
    public static function warnEmptyRegularExpression(Frame $frame, string $function): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf('%s(): Empty regular expression', $function),
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
