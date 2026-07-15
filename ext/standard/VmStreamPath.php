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
        string $paramName = 'filename'
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
            $paramName
        );
    }

    /**
     * Coerce a path operand and reject empty string after null→"" coercion (php-src Z_PARAM_PATH).
     *
     * @throws \ValueError when the coerced path is empty
     * @throws \TypeError when the operand cannot be converted like Zend PHP 8.x
     */
    public static function coerceNonEmptyPathArg(
        Variable $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'filename'
    ): string {
        // Z_PARAM_PATH: null→"" even on 8.4 forward profile; empty path → ValueError (#19145, ext/standard/image.c).
        $path = VmString::coercePathBuiltinArg($var, $function, $argIndex, $paramName, true);
        if ('' === $path) {
            throw new \ValueError(PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);
        }

        return $path;
    }
}
