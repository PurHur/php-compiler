<?php

declare(strict_types=1);

/** Shared empty-path guards for stream/file builtins (php-src streamsfuncs.c; #11016). */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\PathSupport;
use PHPCompiler\VM\Variable;

final class VmStreamPath
{
    /**
     * Z_PARAM_PATH non-empty guard with caller strict_types null rejection (#17060, ext/standard/image.c).
     *
     * @throws \TypeError when caller strict_types rejects null before empty-path ValueError
     */
    public static function coerceNonEmptyPathArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        string $paramName = 'filename',
        string $emptyPathMessage = PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE
    ): string {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::rejectNullString(
                $frame->calledArgs[$argIndex],
                $function,
                $paramName,
                $argIndex,
                $frame
            );
        }

        return self::coerceNonEmptyPathArg(
            $frame->calledArgs[$argIndex],
            $function,
            $argIndex,
            $paramName,
            $emptyPathMessage
        );
    }

    /**
     * Coerce a path operand and reject empty string (php-src Z_PARAM_PATH / non-empty stream path).
     *
     * Null soft-coerces with E_DEPRECATED on PROFILE=8.4, then empty-path ValueError (#20362, #21235).
     * Real empty string "" still ValueError (php-src streams / zend_stream).
     *
     * @throws \ValueError when the coerced path is empty
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x / forward-84
     */
    public static function coerceNonEmptyPathArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'filename',
        string $emptyPathMessage = PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE
    ): string {
        // Z_PARAM_PATH soft-null DEP+coerce on 8.4 (#20362 / #19146).
        $path = VmString::coercePathBuiltinArg($var, $function, $argIndex, $paramName);
        if ('' === $path) {
            throw new \ValueError($emptyPathMessage);
        }

        return $path;
    }
}
