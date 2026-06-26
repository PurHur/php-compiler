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
        self::warn($frame, \sprintf('%s(): Empty regular expression', $function));
    }

    public static function warnPatternCompileFailure(Frame $frame, string $function, string $pattern): void
    {
        $detail = VmPregPattern::patternWarningMessage($pattern);
        if (null === $detail) {
            return;
        }
        self::warn($frame, \sprintf('%s(): %s', $function, $detail));
    }

    /**
     * @param string|list<string> $pattern
     */
    public static function warnPatternCompileFailureOperand(Frame $frame, string $function, string|array $pattern): void
    {
        if (\is_string($pattern)) {
            self::warnPatternCompileFailure($frame, $function, $pattern);

            return;
        }
        foreach ($pattern as $entry) {
            self::warnPatternCompileFailure($frame, $function, $entry);
        }
    }

    private static function warn(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
